<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use League\Flysystem\Filesystem;
use Mcp\Exception\ToolCallException;
use Mcp\Server\Transport\StreamableHttpTransport;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use WBoost\Web\Mcp\Tool\UploadImageTool;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Mcp\TestingMcpClient;
use WBoost\Web\Value\FileSource;

/**
 * `upload_image` (S5-T6) — the FIRST `gallery:write` tool, and the first MCP
 * tool that writes anything a user can see.
 *
 * ## The four properties worth breaking the build over
 *
 * **The picture really joins the gallery.** The done-when is asserted through
 * the real `list_gallery` tool rather than a repository query
 * ({@see testUploadedPictureIsRetrievableThroughListGallery()}): a row that
 * exists but does not LIST is not an asset an agent can go on to use.
 *
 * **Normalisation is not bypassed.** The upload goes through `UploadFileHandler`
 * precisely so `NormalizeImageFormat` runs, and the proof is the stored
 * EXTENSION: a HEIC sent as `holiday.heic` comes back `.jpg`
 * ({@see testPhoneFormatIsStoredNormalizedWithAnExtensionDescribingTheBytes()}).
 * The mirror case is an SVG sent under a `.png` name, which must stay vector
 * bytes under a `.svg` name — the extension describes the CONTENT, both ways.
 *
 * **The cap is the 10 MB decimal one.** Asserted by the number appearing in the
 * refusal, because that number is the whole point: `maxSize: '10m'` means
 * 10 000 000 bytes everywhere else in the app, and a file in the gap between
 * that and 10 MiB accepted here and refused there is the bug the convention
 * exists to prevent. That refusal is reached by calling the tool as a service —
 * see {@see testOversizedPayloadIsRefusedNamingTheByteCap()} for why it cannot
 * be reached over HTTP, and for the HTTP failure that CAN be.
 *
 * **A refusal reveals nothing.** A foreign project, and a foreign FOLDER inside
 * a project the caller can see, fail with the exact words an unknown id gets —
 * compared after masking the echoed id, because "both are errors" passes while
 * one of them leaks.
 */
final class UploadImageTest extends WebTestCase
{
    /** A 1×1 transparent PNG — the smallest thing that is unambiguously a picture. */
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * One browser per test method, created on first use: `createClient()` boots
     * the kernel and may only be called once, while several cases here make two
     * or three MCP calls.
     */
    private null|KernelBrowser $browser = null;

    /**
     * Storage paths written by a test. Minio is NOT rolled back with the
     * database — only the DB runs in a DAMA transaction — so every object this
     * suite creates has to be removed by hand or it leaks into later runs.
     *
     * @var list<string>
     */
    private array $writtenPaths = [];

    protected function tearDown(): void
    {
        if ($this->writtenPaths !== []) {
            $filesystem = self::getContainer()->get(Filesystem::class);

            foreach ($this->writtenPaths as $path) {
                if ($filesystem->fileExists($path)) {
                    $filesystem->delete($path);
                }
            }
        }

        $this->writtenPaths = [];

        parent::tearDown();
    }

    /**
     * THE done-when: a picture uploaded through MCP is a first-class gallery
     * asset a moment later — same id, same url, same pixel size, listed at the
     * root because no folder was named.
     */
    public function testUploadedPictureIsRetrievableThroughListGallery(): void
    {
        $browser = $this->browser();

        $uploaded = $this->upload(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::PROJECT_1_ID,
            self::raster('png', 24, 12),
            'wide.png',
            browser: $browser,
        );

        self::assertSame(24, $uploaded['width']);
        self::assertSame(12, $uploaded['height'], 'A 24×12 picture proves width and height are not transposed.');

        $listed = self::imagesById($this->listGallery(TestDataFixture::PROJECT_1_ID, browser: $browser));

        self::assertArrayHasKey($uploaded['imageId'], $listed, 'The upload is not in the gallery root listing.');

        $image = $listed[$uploaded['imageId']];

        self::assertSame($uploaded['url'], $image['url']);
        self::assertSame(24, $image['width']);
        self::assertSame(12, $image['height']);
        self::assertSame(
            $uploaded['imageId'] . '.png',
            $image['name'],
            'The stored object is named after its own id plus the extension its bytes require.',
        );
    }

    /**
     * A named folder is where the picture lands — and the root is then NOT
     * where it is, which is the half that catches a `directoryId` quietly
     * dropped on the way to the handler.
     */
    public function testUploadIntoANamedFolderLandsThereAndNotInTheRoot(): void
    {
        $browser = $this->browser();

        $uploaded = $this->upload(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::PROJECT_1_ID,
            self::raster('png', 8, 8),
            'in-folder.png',
            TestDataFixture::FILE_DIRECTORY_ALLOWED_ID,
            $browser,
        );

        $folder = self::imagesById($this->listGallery(
            TestDataFixture::PROJECT_1_ID,
            TestDataFixture::FILE_DIRECTORY_ALLOWED_ID,
            $browser,
        ));

        self::assertArrayHasKey($uploaded['imageId'], $folder);

        $root = self::imagesById($this->listGallery(TestDataFixture::PROJECT_1_ID, browser: $browser));

        self::assertArrayNotHasKey($uploaded['imageId'], $root);
    }

    /**
     * The normalisation proof. A HEIC — the default iPhone capture format —
     * passes none of the three readers this app needs, so `UploadFileHandler`
     * transcodes it; the observable consequence is the stored EXTENSION, which
     * describes the BYTES and not the `.heic` name the caller sent.
     */
    public function testPhoneFormatIsStoredNormalizedWithAnExtensionDescribingTheBytes(): void
    {
        $browser = $this->browser();

        $uploaded = $this->upload(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::PROJECT_1_ID,
            self::raster('heic', 40, 20),
            'holiday.heic',
            browser: $browser,
        );

        $image = self::imagesById($this->listGallery(TestDataFixture::PROJECT_1_ID, browser: $browser))[$uploaded['imageId']];

        self::assertSame(
            $uploaded['imageId'] . '.jpg',
            $image['name'],
            'A HEIC has to be stored as a JPEG — the extension describes the bytes, never the client file name.',
        );

        // And the bytes really are a JPEG now: `getimagesizefromstring()` is
        // one of the three readers that could not handle the original at all.
        $stored = self::getContainer()->get(Filesystem::class)->read($this->pathOf($uploaded['url']));
        $size = getimagesizefromstring($stored);

        self::assertIsArray($size);
        self::assertSame(IMAGETYPE_JPEG, $size[2]);
        self::assertSame([40, 20], [$size[0], $size[1]]);

        // The reply describes the STORED picture, so a consumer that places it
        // by the reported size cannot be off.
        self::assertSame(40, $uploaded['width']);
        self::assertSame(20, $uploaded['height']);
    }

    /**
     * The mirror case. An SVG is a first-class vector asset here and is stored
     * untouched — under a `.svg` name even though the caller called it a PNG,
     * because the content decides. It has no pixel size, and null is the honest
     * answer rather than a rasterised guess.
     */
    public function testSvgKeepsItsVectorBytesAndReportsNoPixelSize(): void
    {
        $browser = $this->browser();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';

        $uploaded = $this->upload(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::PROJECT_1_ID,
            $svg,
            'logo.png',
            browser: $browser,
        );

        self::assertNull($uploaded['width']);
        self::assertNull($uploaded['height']);

        $path = $this->pathOf($uploaded['url']);

        self::assertStringEndsWith('.svg', $path, 'A vector sent under a raster name must still be stored as a vector.');
        self::assertSame($svg, self::getContainer()->get(Filesystem::class)->read($path), 'SVG bytes are stored untouched.');
    }

    /**
     * THE size Done-when. It is asserted through a direct service call because
     * a 10 MB picture CANNOT reach this tool over MCP: base64 makes it ~13.3 MB
     * and `StreamableHttpTransport` refuses any body over 4 MiB long before the
     * tool runs (the sibling test below). The refusal therefore has to name
     * BOTH numbers, and this locks the one that is wboost's own — 10 000 000,
     * decimal, the same constant every other upload path mirrors.
     */
    public function testOversizedPayloadIsRefusedNamingTheByteCap(): void
    {
        self::bootKernel();

        $before = $this->galleryFileCount();

        $tool = self::getContainer()->get(UploadImageTool::class);
        $this->authenticate(TestDataFixture::USER_1_EMAIL);

        $refusal = null;

        try {
            $tool(
                TestDataFixture::PROJECT_1_ID,
                base64_encode(str_repeat("\0", 10_000_001)),
                'huge.png',
            );
        } catch (ToolCallException $caught) {
            $refusal = $caught;
        }

        self::assertInstanceOf(ToolCallException::class, $refusal, 'An oversized picture was accepted.');

        $message = $refusal->getMessage();

        self::assertStringContainsString('10000001 bytes', $message, 'The refusal states how big the payload actually was.');
        self::assertStringContainsString('10000000-byte limit', $message, 'The cap is the DECIMAL 10 MB every other upload path applies.');
        self::assertStringContainsString('4 MiB', $message, 'A caller told only "10 MB" would keep resending a 6 MB photo and keep getting a bare 413.');

        self::assertSame($before, $this->galleryFileCount(), 'A refused upload must not create a row.');
    }

    /**
     * The failure a real client actually hits, and the reason the description
     * quotes ~3 MB rather than the 10 MB limit: a payload that does not fit in
     * one request body is rejected by the transport with a bare `413` that
     * carries no advice at all.
     */
    public function testPayloadTooLargeForTheTransportIsRefusedBeforeTheToolRuns(): void
    {
        $browser = $this->browser();
        $before = $this->galleryFileCount();

        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_GALLERY_ONLY);

        TestingMcpClient::request($browser, 'tools/call', [
            'name' => 'upload_image',
            'arguments' => [
                'projectId' => TestDataFixture::PROJECT_1_ID,
                // Just over the 4 MiB body cap once base64 has inflated it.
                'imageBase64' => base64_encode(str_repeat("\0", 3_200_000)),
                'filename' => 'huge.png',
            ],
        ], $sessionId, TestDataFixture::MCP_TOKEN_GALLERY_ONLY);

        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $browser->getResponse()->getStatusCode());
        self::assertSame($before, $this->galleryFileCount());
    }

    /**
     * The advertised ceiling is not a number somebody typed once: it has to
     * survive base64 expansion AND the JSON-RPC envelope inside the SDK's body
     * cap. An SDK bump that lowers that cap fails here instead of turning the
     * advice in the tool description into a lie.
     */
    public function testAdvertisedCeilingFitsInsideTheTransportBodyCap(): void
    {
        $base64Length = (int) (ceil(UploadImageTool::TRANSPORT_LIMIT_BYTES / 3) * 4);

        // A generous allowance for `{"jsonrpc":…,"params":{"arguments":{…}}}`
        // with two UUIDs and a file name in it.
        $envelope = 1024;

        self::assertLessThan(
            StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES,
            $base64Length + $envelope,
        );
    }

    /**
     * Bytes that are not a picture at all are refused, and — the half that
     * matters — nothing is written: a stored archive would become a gallery
     * entry that renders nowhere and that no agent can delete.
     *
     * The payload is a ZIP header rather than, say, a PDF on purpose. Whether
     * ImageMagick can decode a document depends on which delegates the image
     * happens to ship (Ghostscript for PDF), and a test must assert the tool's
     * rule, not the container's build options. Nothing decodes a ZIP.
     */
    public function testPayloadThatIsNotAPictureIsRefusedAndStoresNothing(): void
    {
        // The browser first: `createClient()` refuses to run once the kernel
        // has been booted, and counting the gallery boots it.
        $browser = $this->browser();
        $before = $this->galleryFileCount();

        $result = $this->call(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::PROJECT_1_ID,
            "PK\x03\x04\x14\x00\x00\x00\x08\x00" . str_repeat("\x7f", 64),
            'assets.zip',
            browser: $browser,
        );

        self::assertTrue($result['isError']);
        self::assertStringContainsString('not a picture wboost can use', $result['text']);
        self::assertStringContainsString('Nothing was uploaded', $result['text']);

        self::assertSame($before, $this->galleryFileCount());
    }

    /**
     * A URL is a plausible thing for an agent to try, so it gets the REASON —
     * this server does not fetch what a caller points it at — instead of an
     * "invalid base64" that would send it hunting for an encoding bug.
     */
    public function testUrlIsRefusedWithTheReasonRatherThanADecodeError(): void
    {
        $result = $this->call(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::PROJECT_1_ID,
            'https://example.com/logo.png',
            'logo.png',
            preEncoded: true,
        );

        self::assertTrue($result['isError']);
        self::assertStringContainsString('not a URL', $result['text']);
        self::assertStringContainsString('server-side request forgery', $result['text']);
        self::assertStringNotContainsString('not valid base64', $result['text']);
    }

    /**
     * Garbage is refused as garbage. Strict decoding is what keeps a truncated
     * or hand-mangled payload from being stored as a picture.
     */
    public function testMalformedBase64IsRefused(): void
    {
        $result = $this->call(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::PROJECT_1_ID,
            'this is certainly not base64 ***',
            'logo.png',
            preEncoded: true,
        );

        self::assertTrue($result['isError']);
        self::assertStringContainsString('not valid base64', $result['text']);
    }

    /**
     * A `data:` URI is how a model usually carries a picture around, and its
     * header is stripped rather than being decoded into garbage — one of the
     * two leniencies (the other being wrapped-line whitespace) that keep a
     * correct payload from being refused on a formality.
     */
    public function testDataUriHeaderIsAcceptedAndStripped(): void
    {
        $browser = $this->browser();

        $uploaded = $this->upload(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::PROJECT_1_ID,
            'data:image/png;base64,' . chunk_split(self::PNG_BASE64, 40, "\n"),
            'dot.png',
            browser: $browser,
            preEncoded: true,
        );

        self::assertSame(1, $uploaded['width']);
        self::assertSame(1, $uploaded['height']);
    }

    /**
     * THE anti-enumeration guarantee. USER_1 cannot see PROJECT_2, and there is
     * no project at all behind the second id — both must fail with the very
     * same words, which are also the words `list_gallery` uses.
     */
    public function testForeignProjectIsIndistinguishableFromAnUnknownId(): void
    {
        $foreign = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_2_ID, self::PNG_BASE64, 'dot.png', preEncoded: true);
        $unknown = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, '00000000-0000-0000-0000-0000000000ff', self::PNG_BASE64, 'dot.png', preEncoded: true);

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
     * PROJECT_2 — and the project being written to is one the caller may see,
     * so only the per-folder ownership re-check can refuse it.
     */
    public function testForeignFolderIsIndistinguishableFromAnUnknownFolderId(): void
    {
        $foreign = $this->call(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::PROJECT_1_ID,
            self::PNG_BASE64,
            'dot.png',
            TestDataFixture::FILE_DIRECTORY_PROJECT_2_ID,
            preEncoded: true,
        );
        $unknown = $this->call(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::PROJECT_1_ID,
            self::PNG_BASE64,
            'dot.png',
            '00000000-0000-0000-0000-0000000000fe',
            preEncoded: true,
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
     * The FIRST `gallery:write` tool, so this is where that scope stops being
     * proven by test fixtures alone. Both halves matter and they are
     * independent: a filter is not an authorisation boundary, so the tool is
     * also called BY NAME, exactly as a client with a cached listing would.
     */
    public function testReadOnlyTokenNeitherSeesNorMayCallTheUploadTool(): void
    {
        $browser = $this->browser();
        $before = $this->galleryFileCount();

        self::assertNotContains(
            'upload_image',
            $this->listTools(TestDataFixture::MCP_TOKEN_READ_ONLY),
            'A templates:read token must not learn that upload_image exists.',
        );

        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_READ_ONLY);
        TestingMcpClient::request($browser, 'tools/call', [
            'name' => 'upload_image',
            'arguments' => [
                'projectId' => TestDataFixture::PROJECT_1_ID,
                'imageBase64' => self::PNG_BASE64,
                'filename' => 'dot.png',
            ],
        ], $sessionId, TestDataFixture::MCP_TOKEN_READ_ONLY);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        // The challenge names the scope that was MISSING, so an agent can tell
        // its user exactly what the token needs.
        $challenge = (string) $response->headers->get('WWW-Authenticate');
        self::assertStringStartsWith('Bearer ', $challenge);
        self::assertStringContainsString('error="insufficient_scope"', $challenge);
        self::assertStringContainsString('scope="gallery:write"', $challenge);

        $body = self::decode($response);
        self::assertSame('insufficient_scope', $body['error'] ?? null);
        self::assertSame('gallery:write', $body['scope'] ?? null);

        self::assertSame($before, $this->galleryFileCount(), 'A refused call must not have written anything.');
    }

    /**
     * The other half, on a token holding `gallery:write` and NOTHING else —
     * `MCP_TOKEN_ACTIVE` holds every scope and so could never tell "gallery
     * write unlocked it" apart from "read did". `gallery:write` implies
     * nothing, so the tool this token CAN call is the only tool it can see.
     */
    public function testGalleryScopedTokenSeesTheToolAndCanCallIt(): void
    {
        $browser = $this->browser();

        self::assertSame(['upload_image'], $this->listTools(TestDataFixture::MCP_TOKEN_GALLERY_ONLY));

        $uploaded = $this->upload(
            TestDataFixture::MCP_TOKEN_GALLERY_ONLY,
            TestDataFixture::PROJECT_1_ID,
            self::PNG_BASE64,
            'dot.png',
            browser: $browser,
            preEncoded: true,
        );

        self::assertNotSame('', $uploaded['imageId']);
    }

    /**
     * The description is what makes an agent send bytes instead of a link, keep
     * the picture small enough to survive the transport, and understand that
     * nothing it uploads can be taken back. The generated schema is derived
     * from reflection at compile time and is the only proof the arguments can
     * arrive at all — including that `directoryId` is optional, since the
     * gallery root is a real destination and requiring a folder would make it
     * unreachable.
     */
    public function testToolIsAdvertisedWithAnAgentFacingDescription(): void
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_GALLERY_ONLY);

        TestingMcpClient::request($browser, 'tools/list', sessionId: $sessionId, token: TestDataFixture::MCP_TOKEN_GALLERY_ONLY);

        $result = self::decode($browser->getResponse())['result'];
        self::assertIsArray($result);
        self::assertIsArray($result['tools']);

        self::assertCount(1, $result['tools'], 'A gallery:write token sees exactly one tool.');

        $tool = $result['tools'][0];
        self::assertIsArray($tool);
        self::assertSame('upload_image', $tool['name']);

        $description = $tool['description'];
        self::assertIsString($description);
        // Substrings must not span a line break — the description is the
        // reflowed `__invoke()` docblock, so a wrapped sentence never matches.
        self::assertStringContainsString('The picture is sent INLINE, base64-encoded, in imageBase64.', $description);
        self::assertStringContainsString('and are never fetched', $description);
        self::assertStringContainsString('Nothing is ever overwritten', $description);

        $schema = $tool['inputSchema'];
        self::assertIsArray($schema);
        self::assertIsArray($schema['properties']);
        self::assertArrayHasKey('directoryId', $schema['properties']);
        self::assertSame(['projectId', 'imageBase64', 'filename'], $schema['required']);
    }

    private function browser(): KernelBrowser
    {
        return $this->browser ??= self::createClient();
    }

    /**
     * Calls `upload_image`, asserts it succeeded, and remembers the stored
     * object so `tearDown()` can remove it from Minio.
     *
     * @return array{imageId: string, url: string, width: null|int, height: null|int}
     */
    private function upload(
        string $token,
        string $projectId,
        string $image,
        string $filename,
        null|string $directoryId = null,
        null|KernelBrowser $browser = null,
        bool $preEncoded = false,
    ): array {
        $result = $this->call($token, $projectId, $image, $filename, $directoryId, $browser, $preEncoded);

        self::assertFalse($result['isError'], $result['text']);

        $payload = json_decode($result['text'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        $imageId = $payload['imageId'];
        $url = $payload['url'];
        $width = $payload['width'];
        $height = $payload['height'];

        self::assertIsString($imageId);
        self::assertIsString($url);
        self::assertTrue($width === null || is_int($width));
        self::assertTrue($height === null || is_int($height));

        $this->writtenPaths[] = $this->pathOf($url);

        return ['imageId' => $imageId, 'url' => $url, 'width' => $width, 'height' => $height];
    }

    /**
     * The raw tool outcome: whether it reported an error, and its single text
     * content. A tool error is an ordinary HTTP 200 JSON-RPC RESULT carrying
     * `isError: true` — the MCP contract, so the model can read the message and
     * correct itself instead of seeing a protocol failure.
     *
     * @return array{isError: bool, text: string}
     */
    private function call(
        string $token,
        string $projectId,
        string $image,
        string $filename,
        null|string $directoryId = null,
        null|KernelBrowser $browser = null,
        bool $preEncoded = false,
    ): array {
        $browser ??= $this->browser();
        $sessionId = TestingMcpClient::connect($browser, $token);

        $arguments = [
            'projectId' => $projectId,
            'imageBase64' => $preEncoded ? $image : base64_encode($image),
            'filename' => $filename,
        ];

        if ($directoryId !== null) {
            $arguments['directoryId'] = $directoryId;
        }

        TestingMcpClient::request($browser, 'tools/call', [
            'name' => 'upload_image',
            'arguments' => $arguments,
        ], $sessionId, $token);

        return self::outcome($browser);
    }

    /**
     * The gallery as `list_gallery` reports it — the real tool, over `/_mcp`,
     * because "the picture is in the database" is a weaker claim than "an agent
     * can find it".
     *
     * @return array<string, mixed>
     */
    private function listGallery(
        string $projectId,
        null|string $directoryId = null,
        null|KernelBrowser $browser = null,
    ): array {
        $browser ??= $this->browser();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_ACTIVE);

        $arguments = ['projectId' => $projectId];

        if ($directoryId !== null) {
            $arguments['directoryId'] = $directoryId;
        }

        TestingMcpClient::request($browser, 'tools/call', [
            'name' => 'list_gallery',
            'arguments' => $arguments,
        ], $sessionId, TestDataFixture::MCP_TOKEN_ACTIVE);

        $result = self::outcome($browser);
        self::assertFalse($result['isError'], $result['text']);

        $payload = json_decode($result['text'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @return list<string>
     */
    private function listTools(string $token): array
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, $token);

        TestingMcpClient::request($browser, 'tools/list', sessionId: $sessionId, token: $token);

        $result = self::decode($browser->getResponse())['result'];
        self::assertIsArray($result);
        self::assertIsArray($result['tools']);

        /** @var list<string> $names */
        $names = [];

        foreach ($result['tools'] as $tool) {
            self::assertIsArray($tool);
            $name = $tool['name'];
            self::assertIsString($name);
            $names[] = $name;
        }

        sort($names);

        return $names;
    }

    /**
     * Logs a user in for a DIRECT service call — the `mcp` firewall is
     * stateless, so there is no session to log into, and the one test that
     * cannot go over HTTP still needs the voters to see a real user.
     */
    private function authenticate(string $email): void
    {
        $user = self::getContainer()->get(UserRepository::class)->get($email);

        self::getContainer()->get('security.token_storage')->setToken(
            new PostAuthenticationToken($user, 'main', $user->getRoles()),
        );
    }

    /** How many live pictures PROJECT_1's gallery holds. */
    private function galleryFileCount(): int
    {
        return self::getContainer()->get(FileUploadRepository::class)->countByProjectAndSource(
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            FileSource::ProjectImage,
        );
    }

    /** The storage path behind a public URL this app produced. */
    private function pathOf(string $url): string
    {
        $path = self::getContainer()->get(UploaderHelper::class)->getPathFromPublicUrl($url);

        self::assertIsString($path, sprintf('"%s" is not a URL this app produced.', $url));

        return $path;
    }

    private static function raster(string $format, int $width, int $height): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('#3366cc'));
        $image->setImageFormat($format);

        return $image->getImageBlob();
    }

    /**
     * @return array{isError: bool, text: string}
     */
    private static function outcome(KernelBrowser $browser): array
    {
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
     * Keyed by id, order preserved.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, array<string, mixed>>
     */
    private static function imagesById(array $result): array
    {
        $images = $result['images'];
        self::assertIsArray($images);

        $byId = [];

        foreach ($images as $row) {
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
