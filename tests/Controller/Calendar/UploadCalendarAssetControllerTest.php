<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Calendar;

use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Repository\CalendarAssetRepository;
use WBoost\Web\Services\Calendar\ReadImageDescription;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\Calendar\CalendarAuthorKind;

/**
 * Calendar uploads: pixel size on every path, preview derivative, SVG rules,
 * embedded caption, PDF pages, document text.
 *
 * @covers \WBoost\Web\Controller\Calendar\UploadCalendarAssetController
 * @covers \WBoost\Web\Controller\Calendar\ExtractCalendarDocumentTextController
 * @covers \WBoost\Web\Services\Calendar\CalendarAssetUploader
 * @covers \WBoost\Web\Services\Calendar\ReadImageDescription
 * @covers \WBoost\Web\Services\Calendar\RasterizePdfPage
 * @covers \WBoost\Web\Services\Calendar\ExtractDocumentText
 */
final class UploadCalendarAssetControllerTest extends WebTestCase
{
    use CalendarTesting;

    private const string WORKSPACE_URL = '/calendar/' . TestDataFixture::CALENDAR_1_ID;
    private const string UPLOAD_URL = self::WORKSPACE_URL . '/asset';
    private const string SVG = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>';

    public function testSmallPhotoRecordsItsPixelSizeAndNeedsNoPreview(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $asset = self::upload($browser, self::UPLOAD_URL, $token, 'photo', self::jpeg(640, 300), 'C:\\fotky\\náves.JPG');

        self::assertResponseIsSuccessful();
        self::assertSame(['id', 'url', 'previewUrl', 'width', 'height', 'mime', 'name', 'description', 'kind'], array_keys($asset));
        self::assertSame(640, $asset['width']);
        self::assertSame(300, $asset['height']);
        self::assertSame('image/jpeg', $asset['mime']);
        self::assertSame('náves.JPG', $asset['name']);
        self::assertNull($asset['previewUrl']);
        self::assertNull($asset['description']);
        self::assertSame('photo', $asset['kind']);
        self::assertIsString($asset['id']);
        self::assertIsString($asset['url']);

        $path = sprintf('calendars/%s/%s.jpg', TestDataFixture::CALENDAR_1_ID, $asset['id']);
        self::assertStringEndsWith($path, $asset['url']);
        self::assertTrue(self::getContainer()->get(Filesystem::class)->fileExists($path));

        $stored = self::getContainer()->get(CalendarAssetRepository::class)->get(Uuid::fromString($asset['id']));
        self::assertSame($path, $stored->path);
        self::assertNull($stored->previewPath);
        self::assertSame(CalendarAuthorKind::Designer, $stored->uploadedByKind);
        self::assertSame(TestDataFixture::USER_1_EMAIL, $stored->uploadedBy?->email);
        self::assertGreaterThan(0, $stored->sizeBytes);

        // Uploading does not touch the document; the next bootstrap lists the asset.
        self::assertSame(0, self::calendar(TestDataFixture::CALENDAR_1_ID)->revision);
        $assets = self::bootstrap($browser, self::WORKSPACE_URL)['assets'];
        self::assertIsArray($assets);
        self::assertSame([$asset['id']], array_keys($assets));
        self::assertEquals(array_diff_key($asset, ['id' => true]), $assets[$asset['id']]);
    }

    public function testLargePhotoGetsAPreviewAndItsEmbeddedCaption(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $asset = self::upload($browser, self::UPLOAD_URL, $token, 'photo', self::jpeg(3200, 1500, 'Kostel sv. Jakuba & náves, foto J. Novák'), 'kostel.jpg');

        self::assertResponseIsSuccessful();
        self::assertSame(3200, $asset['width']);
        self::assertSame(1500, $asset['height']);
        self::assertSame('Kostel sv. Jakuba & náves, foto J. Novák', $asset['description']);
        self::assertIsString($asset['id']);
        self::assertIsString($asset['previewUrl']);

        $previewPath = sprintf('calendars/%s/%s-preview.webp', TestDataFixture::CALENDAR_1_ID, $asset['id']);
        self::assertStringEndsWith($previewPath, $asset['previewUrl']);

        $preview = self::getContainer()->get(Filesystem::class)->read($previewPath);
        $size = getimagesizefromstring($preview);
        self::assertIsArray($size);
        self::assertSame(1600, $size[0]);
        self::assertSame(750, $size[1]);
    }

    public function testSvgIsALogoButNotAPhoto(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $logo = self::upload($browser, self::UPLOAD_URL, $token, 'logo', self::SVG, 'znak.svg');
        self::assertResponseIsSuccessful();
        self::assertSame('image/svg+xml', $logo['mime']);
        self::assertNull($logo['width']);
        self::assertNull($logo['height']);
        self::assertIsString($logo['url']);
        self::assertStringEndsWith('.svg', $logo['url']);

        $photo = self::upload($browser, self::UPLOAD_URL, $token, 'photo', self::SVG, 'fotka.svg');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Fotka musí být JPG, PNG nebo WebP.'], $photo);
    }

    public function testRefusals(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        self::upload($browser, self::UPLOAD_URL, 'wrong', 'photo', self::jpeg(20, 20), 'a.jpg');
        self::assertResponseStatusCodeSame(403);

        self::upload($browser, self::UPLOAD_URL, $token, 'cover', self::jpeg(20, 20), 'a.jpg');
        self::assertResponseStatusCodeSame(422);

        $browser->request('POST', self::UPLOAD_URL, ['_token' => $token, 'kind' => 'photo']);
        self::assertResponseStatusCodeSame(400);

        $notAnImage = self::upload($browser, self::UPLOAD_URL, $token, 'photo', 'just some text', 'a.jpg');
        self::assertResponseStatusCodeSame(422);
        self::assertIsString($notAnImage['error']);

        // A PDF is a special-page graphic only.
        self::upload($browser, self::UPLOAD_URL, $token, 'photo', self::pdf(), 'a.pdf');
        self::assertResponseStatusCodeSame(422);

        // The app-wide 10 MB decimal cap.
        $tooLarge = self::upload($browser, self::UPLOAD_URL, $token, 'photo', self::jpeg(20, 20) . str_repeat("\0", 10_000_001), 'big.jpg');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Soubor je větší než 10 MB.'], $tooLarge);

        // Not a member of the project: refused by the route itself.
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_2_EMAIL);
        $browser->request('POST', self::UPLOAD_URL, ['_token' => $token, 'kind' => 'photo']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame([], self::getContainer()->get(CalendarAssetRepository::class)->forCalendar(Uuid::fromString(TestDataFixture::CALENDAR_1_ID)));
    }

    public function testPdfSpecialPageIsRasterised(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $page = self::upload($browser, self::UPLOAD_URL, $token, 'page', self::pdf(), 'mapa.pdf');

        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $page['mime']);
        self::assertSame('mapa.pdf', $page['name']);
        self::assertSame('page', $page['kind']);
        // 310 × 145 mm at 200 dpi.
        self::assertIsInt($page['width']);
        self::assertIsInt($page['height']);
        self::assertEqualsWithDelta(2441, $page['width'], 2);
        self::assertEqualsWithDelta(1142, $page['height'], 2);

        $broken = self::upload($browser, self::UPLOAD_URL, $token, 'page', "%PDF-1.4\nnot really a pdf", 'broken.pdf');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'PDF se nepodařilo přečíst. Nahrajte stránku jako obrázek.'], $broken);
    }

    public function testEmbeddedCaptionFromExifAndXmp(): void
    {
        $reader = new ReadImageDescription();

        self::assertNull($reader->read(self::jpeg(20, 20)));
        self::assertNull($reader->read('not an image'));
        self::assertSame('Jaro v sadu', $reader->read(self::jpeg(20, 20, "  Jaro\n v sadu ")));

        // EXIF ImageDescription (tag 0x010E) wins over XMP.
        $text = "Kostel sv. Jakuba\0";
        $tiff = "II*\0" . pack('V', 8) . pack('v', 1) . pack('vvVV', 0x010E, 2, strlen($text), 26) . pack('V', 0) . $text;

        $image = new \Imagick();
        $image->readImageBlob(self::jpeg(20, 20, 'z XMP'));
        $image->profileImage('exif', "Exif\0\0" . $tiff);
        $bytes = $image->getImageBlob();
        $image->clear();

        self::assertSame('Kostel sv. Jakuba', $reader->read($bytes));
    }

    public function testDocumentText(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));
        $url = self::WORKSPACE_URL . '/document-text';

        $answer = self::uploadDocument($browser, $url, $token, self::docx(['Vážení občané,', '', 'přejeme vám  klidný rok.', 'Starosta']), 'Slovo starosty.docx');
        self::assertResponseIsSuccessful();
        self::assertSame(['text' => "Vážení občané,\npřejeme vám klidný rok.\nStarosta", 'name' => 'Slovo starosty'], $answer);

        $answer = self::uploadDocument($browser, $url, $token, "\xEF\xBB\xBFPrvní řádek\r\n\r\nDruhý řádek\n", 'text.txt');
        self::assertResponseIsSuccessful();
        self::assertSame(['text' => "První řádek\nDruhý řádek", 'name' => 'text'], $answer);

        // Windows-1250 Notepad files are read too; long texts are cut to 6000 characters.
        $answer = self::uploadDocument($browser, $url, $token, (string) iconv('UTF-8', 'Windows-1250', str_repeat('žluťoučký kůň ', 600)), 'ansi.txt');
        self::assertResponseIsSuccessful();
        self::assertIsString($answer['text']);
        self::assertSame(6000, mb_strlen($answer['text']));
        self::assertStringStartsWith('žluťoučký kůň žluťoučký', $answer['text']);

        $answer = self::uploadDocument($browser, $url, $token, self::pdf(), 'text.pdf');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Nahrajte dokument ve formátu DOCX nebo TXT.'], $answer);

        self::uploadDocument($browser, $url, 'wrong', 'x', 'text.txt');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array<string, mixed>
     */
    private static function uploadDocument(\Symfony\Bundle\FrameworkBundle\KernelBrowser $browser, string $url, string $token, string $bytes, string $name): array
    {
        $file = tempnam(sys_get_temp_dir(), 'calendar-test-');
        self::assertIsString($file);
        file_put_contents($file, $bytes);

        $browser->request('POST', $url, ['_token' => $token], ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($file, $name, null, null, true)]);

        return self::json($browser);
    }

    /** A one-page PDF of the calendar's page size (310 × 145 mm). */
    private static function pdf(): string
    {
        $image = new \Imagick();
        $image->newImage(310, 145, new \ImagickPixel('#C84B55'));
        $image->setImageUnits(\Imagick::RESOLUTION_PIXELSPERINCH);
        $image->setImageResolution(25.4, 25.4);
        $image->setImageFormat('pdf');
        $bytes = $image->getImageBlob();
        $image->clear();

        return $bytes;
    }

    /**
     * @param list<string> $paragraphs
     */
    private static function docx(array $paragraphs): string
    {
        $body = '';

        foreach ($paragraphs as $paragraph) {
            $half = intdiv(mb_strlen($paragraph), 2);
            // Two runs per paragraph, as Word splits text.
            $body .= sprintf(
                '<w:p><w:r><w:t xml:space="preserve">%s</w:t></w:r><w:r><w:t>%s</w:t></w:r></w:p>',
                htmlspecialchars(mb_substr($paragraph, 0, $half), ENT_XML1),
                htmlspecialchars(mb_substr($paragraph, $half), ENT_XML1),
            );
        }

        $file = tempnam(sys_get_temp_dir(), 'calendar-docx-');
        self::assertIsString($file);

        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '</w:body></w:document>');
        $zip->close();

        $bytes = (string) file_get_contents($file);
        unlink($file);

        return $bytes;
    }
}
