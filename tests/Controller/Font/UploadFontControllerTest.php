<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Font;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * The fonts page dropzone posts one file per request; every outcome a batch
 * can hit is answered as JSON the queue rows show verbatim.
 *
 * @covers \WBoost\Web\Controller\Font\UploadFontController
 * @covers \WBoost\Web\MessageHandler\Font\AddFontHandler
 * @covers \WBoost\Web\MessageHandler\Font\DeleteFontFaceHandler
 */
final class UploadFontControllerTest extends WebTestCase
{
    private const string OPEN_SANS = __DIR__ . '/../../../vendor/endroid/qr-code/assets/open_sans.ttf';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    public function testUploadFilesTheFaceUnderItsFamilyThenReportsDuplicatesAndCleansUpOnDelete(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $first = $this->upload($browser, self::OPEN_SANS, 'OpenSans-Regular.ttf');
        self::assertResponseIsSuccessful();
        self::assertSame('added', $first['status'] ?? null);
        self::assertSame('Open Sans', $first['family'] ?? null);
        self::assertIsString($first['face'] ?? null);
        self::assertSame(1, $first['faces'] ?? null);

        // The row now carries the file's metadata and the bytes are in storage.
        $font = self::getContainer()->get(GetFonts::class)->byName(Uuid::fromString(TestDataFixture::PROJECT_1_ID), 'Open Sans');
        self::assertCount(1, $font->faces);
        $face = $font->faces[0];
        self::assertSame(400, $face->weight);
        self::assertSame('TTF', $face->format());
        self::assertSame(filesize(self::OPEN_SANS), $face->fileSize);
        self::assertTrue($this->filesystem()->fileExists($face->filePath));

        // The same face again is "already uploaded", not an error — the batch
        // keeps rolling.
        $again = $this->upload($browser, self::OPEN_SANS, 'OpenSans-Regular.ttf');
        self::assertResponseIsSuccessful();
        self::assertSame('exists', $again['status'] ?? null);

        // Deleting the face removes the stored file, not just the row entry.
        $browser->request('GET', sprintf('/delete-font/%s/face/%s', $font->id->toString(), rawurlencode($face->name)));
        self::assertResponseRedirects();
        self::assertFalse($this->filesystem()->fileExists($face->filePath));
    }

    /**
     * A cut uploaded later to a font a manual already presents is switched
     * on in that manual too (kept in the font's face order); cuts the
     * designer switched off stay off. Deleting a face drops it from the
     * manual's list (2026-11-08).
     */
    public function testANewFaceIsEnabledInManualsUsingTheFontAndADeletedOneIsDropped(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $fontId = '00000000-0000-0000-0000-0000000005f2';
        $manualFontId = '00000000-0000-0000-0000-0000000005f3';
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->update('manual', ['type' => 'brand'], ['id' => TestDataFixture::MANUAL_1_ID]);
        $connection->insert('font', [
            'id' => $fontId,
            'project_id' => TestDataFixture::PROJECT_1_ID,
            'created_at' => '2026-11-08 10:00:00',
            'name' => 'Open Sans',
            'faces' => json_encode([
                ['name' => 'Open Sans Light', 'weight' => 300, 'style' => 'Regular', 'filePath' => 'fixtures/fonts/os-light.ttf', 'fileSize' => null],
                ['name' => 'Open Sans ExtraBold', 'weight' => 800, 'style' => 'Regular', 'filePath' => 'fixtures/fonts/os-xbold.ttf', 'fileSize' => null],
            ]),
        ]);
        // Light is switched OFF in the manual on purpose.
        $connection->insert('manual_font', [
            'id' => $manualFontId,
            'manual_id' => TestDataFixture::MANUAL_1_ID,
            'font_id' => $fontId,
            'type' => 'primary',
            'color' => null,
            'position' => 0,
            'created_at' => '2026-11-08 10:00:00',
            'font_faces' => json_encode(['Open Sans ExtraBold']),
        ]);

        $result = $this->upload($browser, self::OPEN_SANS, 'OpenSans-Regular.ttf');
        self::assertSame('added', $result['status'] ?? null);
        $newFace = $result['face'] ?? null;
        self::assertIsString($newFace);

        $enabled = static function () use ($connection, $manualFontId): mixed {
            $json = $connection->fetchOne('SELECT font_faces FROM manual_font WHERE id = ?', [$manualFontId]);
            self::assertIsString($json);

            return json_decode($json, true);
        };
        self::assertSame([$newFace, 'Open Sans ExtraBold'], $enabled());

        $browser->request('GET', sprintf('/delete-font/%s/face/%s', $fontId, rawurlencode('Open Sans ExtraBold')));
        self::assertResponseRedirects();
        self::assertSame([$newFace], $enabled());
    }

    public function testWoff2AndNonFontFilesAreRefusedWithAReason(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $woff2 = $this->tempFile('wOF2' . str_repeat("\0", 64));
        $result = $this->upload($browser, $woff2, 'Brand-Bold.woff2');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('unsupported', $result['status'] ?? null);
        self::assertIsString($result['error'] ?? null);
        self::assertStringContainsString('WOFF2', $result['error']);

        $text = $this->tempFile('this is not a font');
        $result = $this->upload($browser, $text, 'notes.ttf');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('unsupported', $result['status'] ?? null);
        self::assertIsString($result['error'] ?? null);
        self::assertStringContainsString('TTF, OTF a WOFF', $result['error']);
    }

    public function testUploadRequiresEditRightsOnTheProject(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_2_EMAIL);

        $this->upload($browser, self::OPEN_SANS, 'OpenSans-Regular.ttf', 'not-a-token');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(KernelBrowser $browser, string $sourcePath, string $clientName, null|string $token = null): array
    {
        // A copy: Symfony moves uploaded files, the fixture must survive.
        $copy = tempnam(sys_get_temp_dir(), 'font-');
        self::assertIsString($copy);
        copy($sourcePath, $copy);
        $this->tempFiles[] = $copy;

        // The dropzone's hidden token, exactly as the page ships it.
        if ($token === null) {
            $crawler = $browser->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID . '/fonts');
            self::assertResponseIsSuccessful();
            $token = (string) $crawler->filter('input[name="upload_font_form[_token]"]')->attr('value');
        }

        $browser->request(
            'POST',
            '/project/' . TestDataFixture::PROJECT_1_ID . '/fonts/upload',
            ['upload_font_form' => ['_token' => $token]],
            ['upload_font_form' => ['file' => new UploadedFile($copy, $clientName, null, null, true)]],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        $decoded = json_decode((string) $browser->getResponse()->getContent(), true);

        /** @var array<string, mixed> $result */
        $result = is_array($decoded) ? $decoded : [];

        return $result;
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'font-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function filesystem(): Filesystem
    {
        return self::getContainer()->get(Filesystem::class);
    }
}
