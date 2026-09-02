<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Project;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Mcp\TestingMcpClient;
use WBoost\Web\Value\FileSource;

/**
 * `list_gallery` (S2-T4) — driven end to end over `/_mcp`, never as a bare
 * service call: the SDK derives a tool's input schema from reflection at
 * COMPILE time, so a tool can be perfectly correct in isolation and still fail
 * to register.
 *
 * ## The two properties worth breaking the build over
 *
 * **The trash stays invisible.** Deleting a gallery image detaches it from its
 * folder (`directory = NULL`) and stamps `deletedAt`. A listing that filtered
 * by folder alone would therefore show the entire bin at the gallery ROOT —
 * which is why {@see testTrashedImageIsAbsentFromTheRoot()} is the load-bearing
 * one, and why it first asserts the fixture really is trashed and really is
 * detached (otherwise "not in the list" would pass against a row that simply
 * is not there).
 *
 * **A refusal reveals nothing.** A foreign project, and a foreign FOLDER inside
 * a project the caller can see, must both fail with the exact words an unknown
 * id gets — asserted by comparing the two messages after masking the echoed id,
 * because the weaker "both are errors" version passes while one of them leaks.
 */
final class ListGalleryTest extends WebTestCase
{
    /**
     * One browser per test method, created on first use. `createClient()` may
     * only be called once per test (it boots the kernel), and several cases
     * here make two calls — two folders, or two failing ids.
     */
    private null|KernelBrowser $browser = null;

    /**
     * Storage paths written by a test. The object store is NOT rolled back with
     * the database (only the DB runs in a DAMA transaction), so anything
     * written here has to be removed by hand or it leaks into later runs.
     *
     * @var list<string>
     */
    private array $writtenPaths = [];

    protected function tearDown(): void
    {
        if ($this->writtenPaths !== []) {
            $filesystem = self::getContainer()->get(Filesystem::class);

            foreach ($this->writtenPaths as $path) {
                $filesystem->delete($path);
            }
        }

        $this->writtenPaths = [];

        parent::tearDown();
    }

    /**
     * The root is a real location, not just a container for folders: it lists
     * the project's top-level folders AND the pictures filed nowhere.
     */
    public function testRootListingHoldsTheTopLevelFoldersAndTheRootFiles(): void
    {
        $result = $this->listGallery(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID);

        self::assertSame(TestDataFixture::PROJECT_1_ID, $result['projectId']);
        self::assertSame('Project 1', $result['projectName']);
        self::assertNull($result['directoryId']);
        self::assertNull($result['directoryName']);
        self::assertNull($result['parentDirectoryId']);
        self::assertSame([], $result['path'], 'The breadcrumb of the root is empty.');

        self::assertSame(
            // Alphabetical: "Other" before "Photos". The nested folder is NOT
            // here — one tree LEVEL, not the whole tree.
            [TestDataFixture::FILE_DIRECTORY_OTHER_ID, TestDataFixture::FILE_DIRECTORY_ALLOWED_ID],
            array_keys(self::directoriesById($result)),
        );

        self::assertSame(
            [TestDataFixture::FILE_IN_ROOT_ID],
            array_keys(self::imagesById($result)),
            'Only the file that sits in no folder belongs to the root listing.',
        );
    }

    /**
     * A sub-folder lists its OWN pictures and its OWN children — the files of
     * the root and of sibling folders stay where they are.
     */
    public function testSubdirectoryListingHoldsItsOwnFilesAndChildren(): void
    {
        $result = $this->listGallery(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::PROJECT_1_ID,
            TestDataFixture::FILE_DIRECTORY_ALLOWED_ID,
        );

        self::assertSame(TestDataFixture::FILE_DIRECTORY_ALLOWED_ID, $result['directoryId']);
        self::assertSame('Photos', $result['directoryName']);
        self::assertNull($result['parentDirectoryId'], 'A top-level folder has no parent folder — its parent is the root.');
        self::assertSame(
            [['id' => TestDataFixture::FILE_DIRECTORY_ALLOWED_ID, 'name' => 'Photos']],
            $result['path'],
            'The breadcrumb includes the folder being listed.',
        );

        self::assertSame(
            [TestDataFixture::FILE_DIRECTORY_NESTED_ID],
            array_keys(self::directoriesById($result)),
        );

        self::assertSame(
            [TestDataFixture::FILE_IN_ALLOWED_ID],
            array_keys(self::imagesById($result)),
        );
    }

    /**
     * Going deeper: the nested folder reports the parent id and the full
     * breadcrumb, which is everything an agent needs to walk back up. It is
     * empty on purpose — a folder with nothing in it is an ordinary state.
     */
    public function testNestedFolderReportsItsParentAndTheFullBreadcrumb(): void
    {
        $result = $this->listGallery(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::PROJECT_1_ID,
            TestDataFixture::FILE_DIRECTORY_NESTED_ID,
        );

        self::assertSame(TestDataFixture::FILE_DIRECTORY_NESTED_ID, $result['directoryId']);
        self::assertSame('Logos', $result['directoryName']);
        self::assertSame(TestDataFixture::FILE_DIRECTORY_ALLOWED_ID, $result['parentDirectoryId']);
        self::assertSame(
            [
                ['id' => TestDataFixture::FILE_DIRECTORY_ALLOWED_ID, 'name' => 'Photos'],
                ['id' => TestDataFixture::FILE_DIRECTORY_NESTED_ID, 'name' => 'Logos'],
            ],
            $result['path'],
            'The breadcrumb runs root → … → the listed folder.',
        );

        self::assertSame([], $result['directories']);
        self::assertSame([], $result['images']);
    }

    /**
     * THE trash guarantee, at the level where it is load-bearing: a trashed
     * file is DETACHED from its folder, so the root is exactly where a listing
     * that filtered by folder alone would surface the whole bin.
     */
    public function testTrashedImageIsAbsentFromTheRoot(): void
    {
        $browser = $this->browser();

        // Without this the assertion below could pass against a fixture that
        // simply does not exist, or one that was never actually trashed.
        $trashed = self::getContainer()->get(FileUploadRepository::class)
            ->get(Uuid::fromString(TestDataFixture::FILE_TRASHED_ID));

        self::assertTrue($trashed->isTrashed());
        self::assertNull($trashed->directory, 'Trashing detaches the file — that is what makes it look like a root file.');

        $result = $this->listGallery(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID, browser: $browser);

        self::assertArrayNotHasKey(
            TestDataFixture::FILE_TRASHED_ID,
            self::imagesById($result),
            'A file in the bin must never be offered as a usable asset.',
        );
    }

    /**
     * The other half: it is not hiding in the folder it was deleted from
     * either. `restoreDirectory` still points there, and only a listing that
     * read the LIVE `directory` column gets this right.
     */
    public function testTrashedImageIsAbsentFromTheFolderItWasDeletedFrom(): void
    {
        $result = $this->listGallery(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::PROJECT_1_ID,
            TestDataFixture::FILE_DIRECTORY_ALLOWED_ID,
        );

        self::assertArrayNotHasKey(TestDataFixture::FILE_TRASHED_ID, self::imagesById($result));
    }

    /** There is no bin among the folders either — it is not a place to browse. */
    public function testTrashIsNotOfferedAsAFolder(): void
    {
        $result = $this->listGallery(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID);

        foreach (self::directoriesById($result) as $directory) {
            self::assertNotSame('Koš', $directory['name']);
        }
    }

    /**
     * The Done-when "shared" case, and the proof the gate is the ordinary
     * voter: a user who owns nothing reaches the gallery of the project shared
     * with them, and sees exactly what the owner sees.
     */
    public function testSharedUserSeesTheSharedProjectsGallery(): void
    {
        $shared = $this->listGallery(TestDataFixture::MCP_TOKEN_SHARED_USER, TestDataFixture::PROJECT_1_ID);
        $owner = $this->listGallery(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID);

        self::assertSame($owner, $shared);
    }

    /**
     * THE anti-enumeration guarantee. USER_1 cannot see PROJECT_2, and there is
     * no project at all behind the second id — both must fail with the very
     * same words.
     */
    public function testForeignProjectIsIndistinguishableFromAnUnknownId(): void
    {
        $foreign = $this->callListGallery(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_2_ID);
        $unknown = $this->callListGallery(TestDataFixture::MCP_TOKEN_ACTIVE, '00000000-0000-0000-0000-0000000000ff');

        self::assertTrue($foreign['isError']);
        self::assertTrue($unknown['isError']);

        self::assertSame(
            str_replace(TestDataFixture::PROJECT_2_ID, '<id>', $foreign['text']),
            str_replace('00000000-0000-0000-0000-0000000000ff', '<id>', $unknown['text']),
        );

        self::assertStringContainsString('was not found, or this account cannot access it', $foreign['text']);
    }

    /**
     * The same rule one level down. The folder id is REAL — it belongs to
     * PROJECT_2 — and the project being listed is one the caller may see, so
     * only the per-folder ownership re-check can refuse it. It must do so in
     * the words an id that matches nothing gets.
     */
    public function testForeignDirectoryIsIndistinguishableFromAnUnknownDirectoryId(): void
    {
        $foreign = $this->callListGallery(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::PROJECT_1_ID,
            TestDataFixture::FILE_DIRECTORY_PROJECT_2_ID,
        );
        $unknown = $this->callListGallery(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::PROJECT_1_ID,
            '00000000-0000-0000-0000-0000000000fe',
        );

        self::assertTrue($foreign['isError']);
        self::assertTrue($unknown['isError']);

        self::assertSame(
            str_replace(TestDataFixture::FILE_DIRECTORY_PROJECT_2_ID, '<id>', $foreign['text']),
            str_replace('00000000-0000-0000-0000-0000000000fe', '<id>', $unknown['text']),
        );

        self::assertStringContainsString('was not found in this project', $foreign['text']);
    }

    /**
     * The admin reaches PROJECT_2 through the very code path that just refused
     * USER_1 — the refusal is the voter talking, not a hard-coded owner check.
     */
    public function testAdminReachesTheGalleryOfAProjectTheyDoNotOwn(): void
    {
        $result = $this->listGallery(TestDataFixture::MCP_TOKEN_ADMIN, TestDataFixture::PROJECT_2_ID);

        self::assertSame('Project 2', $result['projectName']);
        self::assertSame(
            [TestDataFixture::FILE_DIRECTORY_PROJECT_2_ID],
            array_keys(self::directoriesById($result)),
        );
    }

    /**
     * Strings that cannot be ids are NOT folded into the not-found messages:
     * they reveal nothing about what exists, and an agent that sent a NAME
     * needs to be told so rather than sent hunting for a permission problem.
     */
    public function testMalformedIdsAreRejectedWithActionableMessages(): void
    {
        $project = $this->callListGallery(TestDataFixture::MCP_TOKEN_ACTIVE, 'Project 1');

        self::assertTrue($project['isError']);
        self::assertStringContainsString('is not a valid project id', $project['text']);
        self::assertStringContainsString('get_context', $project['text']);

        $directory = $this->callListGallery(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID, 'Photos');

        self::assertTrue($directory['isError']);
        self::assertStringContainsString('is not a valid folder id', $directory['text']);
    }

    /**
     * Every per-image field, on a picture whose bytes really are in the object
     * store. The size is 24 × 12 on purpose: a square would let a transposed
     * width/height pass.
     */
    public function testImageFieldsAreReportedForARasterPicture(): void
    {
        $browser = $this->browser();

        $path = 'fixtures/mcp-gallery-wide.png';
        $this->write($path, $this->raster('png', 24, 12));
        $fileId = $this->persistRootImage($path);

        $image = self::imagesById(
            $this->listGallery(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID, browser: $browser),
        )[$fileId];

        self::assertSame(
            [
                'id' => $fileId,
                'name' => 'mcp-gallery-wide.png',
                'originalName' => null,
                'url' => self::getContainer()->get(UploaderHelper::class)->getPublicPath($path),
                'width' => 24,
                'height' => 12,
            ],
            $image,
        );
    }

    /**
     * What the row knows wins over reading the file: a size recorded at upload
     * (and the uploaded file name) are reported without touching storage — the
     * object behind this row is deliberately absent.
     */
    public function testRecordedNameAndSizeAreReportedWithoutReadingTheFile(): void
    {
        $browser = $this->browser();

        $fileId = $this->persistRootImage('fixtures/mcp-gallery-recorded.png', 'pozadi-modre.png', 100, 50);

        $image = self::imagesById(
            $this->listGallery(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID, browser: $browser),
        )[$fileId];

        self::assertSame('pozadi-modre.png', $image['originalName']);
        self::assertSame([100, 50], [$image['width'], $image['height']]);
    }

    /**
     * An SVG has no pixel size — it scales to whatever box it is placed in —
     * and wboost stores it untouched precisely to keep it that way. Nulls are
     * the honest answer; a rasterized guess would mis-size every placement made
     * from it.
     */
    public function testSvgReportsNoPixelSize(): void
    {
        $browser = $this->browser();

        $path = 'fixtures/mcp-gallery-logo.svg';
        $this->write($path, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');
        $fileId = $this->persistRootImage($path);

        $image = self::imagesById(
            $this->listGallery(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID, browser: $browser),
        )[$fileId];

        self::assertNull($image['width']);
        self::assertNull($image['height']);
        self::assertSame('mcp-gallery-logo.svg', $image['name'], 'The extension is what tells an agent it is dealing with a vector.');
    }

    /**
     * A row whose object is gone still LISTS — an agent must be able to see
     * that the picture exists and is broken. Only its size is unknown.
     */
    public function testImageWithUnreadableBytesStillListsWithoutASize(): void
    {
        $browser = $this->browser();

        // Deliberately not written to the object store.
        $fileId = $this->persistRootImage('fixtures/mcp-gallery-missing.png');

        $image = self::imagesById(
            $this->listGallery(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID, browser: $browser),
        )[$fileId];

        self::assertNull($image['width']);
        self::assertNull($image['height']);
    }

    /**
     * The description is what makes an agent reach for this tool, look in the
     * root before giving up, and understand that nothing here can be deleted.
     */
    public function testToolIsAdvertisedWithAnAgentFacingDescription(): void
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_READ_ONLY);

        TestingMcpClient::request($browser, 'tools/list', sessionId: $sessionId, token: TestDataFixture::MCP_TOKEN_READ_ONLY);

        $result = self::decode($browser->getResponse())['result'];
        self::assertIsArray($result);
        self::assertIsArray($result['tools']);

        $tool = null;

        foreach ($result['tools'] as $candidate) {
            self::assertIsArray($candidate);

            if ($candidate['name'] === 'list_gallery') {
                $tool = $candidate;
            }
        }

        self::assertIsArray($tool, 'list_gallery is not advertised to a templates:read token.');

        $description = $tool['description'];
        self::assertIsString($description);
        // Substrings must not span a line break — the description is the
        // reflowed `__invoke()` docblock, so a wrapped sentence never matches.
        self::assertStringContainsString('Lists one level of a project', $description);
        self::assertStringContainsString('This tool only reads', $description);

        // Both arguments reach the generated schema, and only the project is
        // required: a schema demanding directoryId would make the ROOT — the
        // only entry point into the tree — unreachable.
        $schema = $tool['inputSchema'];
        self::assertIsArray($schema);
        self::assertIsArray($schema['properties']);
        self::assertArrayHasKey('projectId', $schema['properties']);
        self::assertArrayHasKey('directoryId', $schema['properties']);
        self::assertSame(['projectId'], $schema['required']);
    }

    private function browser(): KernelBrowser
    {
        return $this->browser ??= self::createClient();
    }

    /**
     * Calls `list_gallery` and returns its decoded payload, failing the test if
     * the tool reported an error.
     *
     * @return array<string, mixed>
     */
    private function listGallery(
        string $token,
        string $projectId,
        null|string $directoryId = null,
        null|KernelBrowser $browser = null,
    ): array {
        $result = $this->callListGallery($token, $projectId, $directoryId, $browser);

        self::assertFalse($result['isError'], $result['text']);

        $payload = json_decode($result['text'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * The raw tool outcome: whether it reported an error, and its single text
     * content. A tool error is an ordinary HTTP 200 JSON-RPC RESULT carrying
     * `isError: true` — the MCP contract, so the model can read the message and
     * correct itself instead of seeing a protocol failure.
     *
     * @return array{isError: bool, text: string}
     */
    private function callListGallery(
        string $token,
        string $projectId,
        null|string $directoryId = null,
        null|KernelBrowser $browser = null,
    ): array {
        $browser ??= $this->browser();
        $sessionId = TestingMcpClient::connect($browser, $token);

        $arguments = ['projectId' => $projectId];

        if ($directoryId !== null) {
            $arguments['directoryId'] = $directoryId;
        }

        TestingMcpClient::request($browser, 'tools/call', [
            'name' => 'list_gallery',
            'arguments' => $arguments,
        ], $sessionId, $token);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = self::decode($response);
        self::assertArrayNotHasKey('error', $payload, (string) $response->getContent());

        $result = $payload['result'];
        self::assertIsArray($result);
        self::assertIsArray($result['content']);
        self::assertIsArray($result['content'][0]);

        $text = $result['content'][0]['text'];
        self::assertIsString($text);

        return ['isError' => $result['isError'] === true, 'text' => $text];
    }

    /**
     * A gallery image at the root of PROJECT_1, created HERE rather than in the
     * shared fixtures: these rows exist to exercise one field each, and the
     * fixture gallery is read by half a dozen other suites.
     */
    private function persistRootImage(string $path, null|string $originalName = null, null|int $width = null, null|int $height = null): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $file = new FileUpload(
            Uuid::uuid4(),
            self::getContainer()->get(ProjectRepository::class)->get(Uuid::fromString(TestDataFixture::PROJECT_1_ID)),
            new DateTimeImmutable(),
            FileSource::ProjectImage,
            $path,
            null,
            $originalName,
            $width,
            $height,
        );

        $entityManager->persist($file);
        $entityManager->flush();

        return $file->id->toString();
    }

    private function write(string $path, string $contents): void
    {
        self::getContainer()->get(Filesystem::class)->write($path, $contents);
        $this->writtenPaths[] = $path;
    }

    private function raster(string $format, int $width, int $height): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('#3366cc'));
        $image->setImageFormat($format);

        return $image->getImageBlob();
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, array<string, mixed>>
     */
    private static function directoriesById(array $result): array
    {
        $directories = $result['directories'];
        self::assertIsArray($directories);

        return self::byId($directories);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, array<string, mixed>>
     */
    private static function imagesById(array $result): array
    {
        $images = $result['images'];
        self::assertIsArray($images);

        return self::byId($images);
    }

    /**
     * Keyed by id, ORDER PRESERVED — every caller that asserts on
     * `array_keys()` is asserting the tool's ordering as well.
     *
     * @param array<array-key, mixed> $rows
     *
     * @return array<string, array<string, mixed>>
     */
    private static function byId(array $rows): array
    {
        $byId = [];

        foreach ($rows as $row) {
            self::assertIsArray($row);
            $id = $row['id'];
            self::assertIsString($id);

            /** @var array<string, mixed> $row */
            $byId[$id] = $row;
        }

        return $byId;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
