<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Font;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Entity\Font;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\Font\VariableFontExpander;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Services\Font\VariableFontExpanderTest;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\FontFace;

/**
 * A variable font dropped on the fonts page becomes one face per named
 * instance — the same faces, names, weights and styles the designer would get
 * by uploading the foundry's static cuts one by one (2026-11-08).
 *
 * @covers \WBoost\Web\Controller\Font\UploadFontController
 * @covers \WBoost\Web\Services\Font\AddVariableFontFaces
 */
final class UploadVariableFontControllerTest extends WebTestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];

        $filesystem = self::getContainer()->get(Filesystem::class);
        foreach ($filesystem->listContents('fonts/' . TestDataFixture::PROJECT_1_ID)->toArray() as $item) {
            if (str_starts_with(basename($item->path()), 'Inter ')) {
                $filesystem->delete($item->path());
            }
        }

        parent::tearDown();
    }

    public function testAVariableFontBecomesOneStaticFacePerNamedInstance(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $result = $this->upload($browser, VariableFontExpanderTest::INTER_VARIABLE, 'Inter-VariableFont_slnt,wght.ttf');

        self::assertResponseIsSuccessful();
        self::assertSame('added', $result['status'] ?? null);
        self::assertTrue($result['variable'] ?? null);
        self::assertSame('Inter', $result['family'] ?? null);
        self::assertSame(18, $result['faces'] ?? null);
        self::assertSame(array_column(VariableFontExpanderTest::INTER_CUTS, 0), $result['created'] ?? null);
        self::assertSame([], $result['existing'] ?? null);
        self::assertSame('Variabilní písmo — vytvořeno 18 řezů.', $result['message'] ?? null);

        $font = $this->inter();
        // Font::addFontFace orders a fresh family uprights Thin→Black, then italics.
        self::assertSame(
            [
                'Inter Thin', 'Inter ExtraLight', 'Inter Light', 'Inter Regular', 'Inter Medium',
                'Inter SemiBold', 'Inter Bold', 'Inter ExtraBold', 'Inter Black',
                'Inter Thin Italic', 'Inter ExtraLight Italic', 'Inter Light Italic', 'Inter Italic',
                'Inter Medium Italic', 'Inter SemiBold Italic', 'Inter Bold Italic', 'Inter ExtraBold Italic',
                'Inter Black Italic',
            ],
            $font->faceNames(),
        );

        $faces = self::facesByName($font);
        self::assertSame([800, 'Regular', false], [$faces['Inter ExtraBold']->weight, $faces['Inter ExtraBold']->style, $faces['Inter ExtraBold']->isItalic()]);
        self::assertSame([700, 'Bold Italic', true], [$faces['Inter Bold Italic']->weight, $faces['Inter Bold Italic']->style, $faces['Inter Bold Italic']->isItalic()]);
        self::assertSame([400, 'Italic', true], [$faces['Inter Italic']->weight, $faces['Inter Italic']->style, $faces['Inter Italic']->isItalic()]);

        // Every face is its own STATIC file in storage — nothing downstream
        // (editor, fill, Gotenberg render, print PDF) ever sees the variable one.
        $filesystem = self::getContainer()->get(Filesystem::class);
        $expander = self::getContainer()->get(VariableFontExpander::class);
        foreach ($font->faces as $face) {
            self::assertSame('TTF', $face->format());
            self::assertTrue($filesystem->fileExists($face->filePath), $face->filePath);
            self::assertSame($filesystem->fileSize($face->filePath), $face->fileSize);

            $copy = $this->tempFile();
            file_put_contents($copy, $filesystem->read($face->filePath));
            self::assertFalse($expander->isVariable($copy), $face->name . ' is stored as a variable font.');
        }
    }

    /**
     * The user's ask, literally: "the output is exactly the same as if we
     * uploaded the faces one by one". The static cuts are uploaded one by one
     * first, the family is deleted, then the variable file — the face rows
     * must be identical.
     */
    public function testTheResultIsIdenticalToUploadingTheStaticCutsOneByOne(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $expander = self::getContainer()->get(VariableFontExpander::class);
        $expanded = $expander->expand(VariableFontExpanderTest::INTER_VARIABLE);
        try {
            foreach ($expanded->faces as $face) {
                $one = $this->upload($browser, $face->path, $face->clientName);
                self::assertSame('added', $one['status'] ?? null, $face->clientName);
                self::assertArrayNotHasKey('variable', $one, 'A static cut takes the ordinary path.');
            }
        } finally {
            $expander->discard($expanded);
        }

        $oneByOne = self::describe($this->inter());

        $browser->request('GET', '/delete-font/' . $this->inter()->id->toString());
        self::assertResponseRedirects();

        $result = $this->upload($browser, VariableFontExpanderTest::INTER_VARIABLE, 'Inter-VariableFont_slnt,wght.ttf');
        self::assertSame('added', $result['status'] ?? null);

        self::assertSame($oneByOne, self::describe($this->inter()));
    }

    /**
     * Faces the family already has (a static Bold uploaded earlier) are
     * reported, not duplicated; a second upload of the same variable file is
     * "already uploaded" as a whole.
     */
    public function testFacesTheFamilyAlreadyHasAreReportedAndOnlyTheMissingOnesAdded(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->insert('font', [
            'id' => '00000000-0000-0000-0000-0000000005f7',
            'project_id' => TestDataFixture::PROJECT_1_ID,
            'created_at' => '2026-11-08 10:00:00',
            'name' => 'Inter',
            'faces' => json_encode([
                ['name' => 'Inter Bold', 'weight' => 700, 'style' => 'Bold', 'filePath' => 'fixtures/fonts/inter-bold.ttf', 'fileSize' => null],
            ]),
        ]);

        $result = $this->upload($browser, VariableFontExpanderTest::INTER_VARIABLE, 'Inter-Variable.ttf');
        self::assertSame('added', $result['status'] ?? null);
        self::assertCount(17, (array) ($result['created'] ?? []));
        self::assertSame(['Inter Bold'], $result['existing'] ?? null);
        self::assertSame(18, $result['faces'] ?? null);
        self::assertSame('Variabilní písmo — vytvořeno 17 řezů, 1 už byl nahraný.', $result['message'] ?? null);
        self::assertSame('fixtures/fonts/inter-bold.ttf', self::facesByName($this->inter())['Inter Bold']->filePath, 'The existing face is kept, not replaced.');

        $again = $this->upload($browser, VariableFontExpanderTest::INTER_VARIABLE, 'Inter-Variable.ttf');
        self::assertResponseIsSuccessful();
        self::assertSame('exists', $again['status'] ?? null);
        self::assertSame([], $again['created'] ?? null);
        self::assertSame('Variabilní písmo — všech 18 řezů už je nahraných.', $again['message'] ?? null);
    }

    private function inter(): Font
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(GetFonts::class)->byName(Uuid::fromString(TestDataFixture::PROJECT_1_ID), 'Inter');
    }

    /**
     * @return array<string, FontFace>
     */
    private static function facesByName(Font $font): array
    {
        $faces = [];
        foreach ($font->faces as $face) {
            $faces[$face->name] = $face;
        }

        return $faces;
    }

    /**
     * Everything about a face except where its bytes live.
     *
     * @return list<array{string, int, string, string, null|int}>
     */
    private static function describe(Font $font): array
    {
        return array_values(array_map(
            static fn (FontFace $face): array => [$face->name, $face->weight, $face->style, $face->format(), $face->fileSize],
            $font->faces,
        ));
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'font-');
        self::assertIsString($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(KernelBrowser $browser, string $sourcePath, string $clientName): array
    {
        // A copy: Symfony moves uploaded files, the fixture must survive.
        $copy = $this->tempFile();
        copy($sourcePath, $copy);

        $crawler = $browser->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID . '/fonts');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="upload_font_form[_token]"]')->attr('value');

        $browser->request(
            'POST',
            '/project/' . TestDataFixture::PROJECT_1_ID . '/fonts/upload',
            ['upload_font_form' => ['_token' => $token]],
            ['upload_font_form' => ['file' => new UploadedFile($copy, $clientName, null, null, true)]],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        $decoded = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertIsArray($decoded, (string) $browser->getResponse()->getContent());

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
