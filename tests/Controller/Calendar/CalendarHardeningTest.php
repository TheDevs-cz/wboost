<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Calendar;

use Doctrine\DBAL\Connection;
use League\Flysystem\Filesystem;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Controller\Calendar\ClientCalendarHistoryController;
use WBoost\Web\Controller\Calendar\SaveClientCalendarController;
use WBoost\Web\Controller\Calendar\UploadClientCalendarAssetController;
use WBoost\Web\Repository\CalendarAssetRepository;
use WBoost\Web\Services\Calendar\CalendarAssetUploader;
use WBoost\Web\Services\Calendar\CalendarHistoryWriter;
use WBoost\Web\Services\Calendar\ExtractDocumentText;
use WBoost\Web\Services\Image\ImageDecodeLimits;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * The findings of the 2026-11 code review of the calendar module, one test
 * each: sideways phone photos, decode bombs (pixels, PDF page size, DOCX),
 * the budgets of the public client link, control characters, hostile ids.
 *
 * @covers \WBoost\Web\Services\Calendar\CalendarAssetUploader
 * @covers \WBoost\Web\Services\Calendar\RasterizePdfPage
 * @covers \WBoost\Web\Services\Calendar\ExtractDocumentText
 * @covers \WBoost\Web\Services\Calendar\CalendarText
 * @covers \WBoost\Web\Services\Calendar\CalendarHistoryWriter
 * @covers \WBoost\Web\Controller\Calendar\UploadClientCalendarAssetController
 * @covers \WBoost\Web\Controller\Calendar\SaveClientCalendarController
 * @covers \WBoost\Web\Controller\Calendar\ClientCalendarHistoryController
 */
final class CalendarHardeningTest extends WebTestCase
{
    use CalendarTesting;

    private const string WORKSPACE_URL = '/calendar/' . TestDataFixture::CALENDAR_1_ID;
    private const string UPLOAD_URL = self::WORKSPACE_URL . '/asset';
    private const string SAVE_URL = self::WORKSPACE_URL . '/save';
    private const string CLIENT_URL = '/k/' . TestDataFixture::CALENDAR_2_TOKEN;

    /**
     * A portrait phone photo is stored landscape with EXIF Orientation = 6.
     * The recorded size, the stored file and the preview are all upright.
     */
    public function testASidewaysPhonePhotoIsStoredUpright(): void
    {
        $browser = self::designer();
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $asset = self::upload($browser, self::UPLOAD_URL, $token, 'photo', self::sidewaysJpeg(2000, 1200), 'na-vysku.jpg');

        self::assertResponseIsSuccessful();
        self::assertSame('image/jpeg', $asset['mime']);
        self::assertSame([1200, 2000], [$asset['width'], $asset['height']]);
        self::assertIsString($asset['id']);

        $filesystem = self::getContainer()->get(Filesystem::class);
        $base = sprintf('calendars/%s/%s', TestDataFixture::CALENDAR_1_ID, $asset['id']);

        $stored = getimagesizefromstring($filesystem->read($base . '.jpg'));
        self::assertIsArray($stored);
        self::assertSame([1200, 2000], [$stored[0], $stored[1]]);

        $preview = getimagesizefromstring($filesystem->read($base . '-preview.webp'));
        self::assertIsArray($preview);
        self::assertSame([960, 1600], [$preview[0], $preview[1]]);
    }

    /**
     * 10 MB bounds the bytes, not the pixels — a flat PNG of 64 megapixels is
     * a few kilobytes. It is refused on its header, before any decode.
     */
    public function testAPictureWithTooManyPixelsIsRefusedBeforeItIsDecoded(): void
    {
        $browser = self::designer();
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $bomb = self::flatPng(8000, 8000);
        self::assertLessThan(1_000_000, strlen($bomb));
        self::assertGreaterThan(ImageDecodeLimits::MAX_PIXELS, 8000 * 8000);

        $answer = self::upload($browser, self::UPLOAD_URL, $token, 'photo', $bomb, 'obri.png');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => CalendarAssetUploader::TOO_MANY_PIXELS], $answer);
        self::assertSame(0, self::storedAssets(TestDataFixture::CALENDAR_1_ID));

        // A real print photo (24 MP) is far inside the limit.
        self::assertFalse(ImageDecodeLimits::exceeds(6000, 4000));
        self::assertFalse(ImageDecodeLimits::exceeds(8660, 5773));
    }

    /**
     * An SVG hidden behind a long comment used to slip past the 1 kB sniff
     * and was RENDERED by ImageMagick on the server — through the public link.
     */
    public function testAPaddedSvgIsNeverRenderedAndIsNoPhoto(): void
    {
        $svg = '<?xml version="1.0"?><!--' . str_repeat('x', 4000) . '--><svg xmlns="http://www.w3.org/2000/svg" width="40" height="20"><rect width="40" height="20"/></svg>';

        $browser = self::createClient();
        $token = self::csrf(self::bootstrap($browser, self::CLIENT_URL));

        foreach (['kresba.svg', 'foto.jpg', 'foto.png'] as $name) {
            $answer = self::upload($browser, self::CLIENT_URL . '/asset', $token, 'photo', $svg, $name);
            self::assertResponseStatusCodeSame(422, $name);
            self::assertSame(['error' => CalendarAssetUploader::PHOTO_FORMAT], $answer, $name);
        }

        // ImageMagick's own script format is text too.
        $answer = self::upload($browser, self::CLIENT_URL . '/asset', $token, 'photo', "push graphic-context\nviewbox 0 0 10 10\npop graphic-context", 'foto.mvg');
        self::assertResponseStatusCodeSame(422);

        // Encapsulated PostScript with its binary header sniffs as an image
        // (`image/x-eps`) and would be handed to Ghostscript; a TIFF is no
        // photo format either.
        $eps = "\xC5\xD0\xD3\xC6" . pack('V6v', 30, 60, 0, 0, 0, 0, 0xFFFF) . "%!PS-Adobe-3.0 EPSF-3.0\n%%BoundingBox: 0 0 10 10\nshowpage\n%%EOF\n";
        $tiff = new \Imagick();
        $tiff->newImage(30, 20, new \ImagickPixel('#7A93B8'));
        $tiff->setImageFormat('tiff');

        foreach (['foto.eps' => $eps, 'foto.tif' => $tiff->getImageBlob()] as $name => $bytes) {
            $answer = self::upload($browser, self::CLIENT_URL . '/asset', $token, 'photo', $bytes, $name);
            self::assertResponseStatusCodeSame(422, $name);
            self::assertSame(['error' => CalendarAssetUploader::PHOTO_FORMAT], $answer, $name);
        }

        self::assertSame(0, self::storedAssets(TestDataFixture::CALENDAR_2_ID));

        // For the designer it is what it is: a vector logo, stored as it came.
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));
        $logo = self::upload($browser, self::UPLOAD_URL, $token, 'logo', $svg, 'znak.svg');
        self::assertResponseIsSuccessful();
        self::assertSame('image/svg+xml', $logo['mime']);
        self::assertNull($logo['width']);
    }

    public function testAGifIsNotAPhoto(): void
    {
        $image = new \Imagick();
        $image->newImage(30, 20, new \ImagickPixel('#7A93B8'));
        $image->setImageFormat('gif');
        $gif = $image->getImageBlob();
        $image->clear();

        $browser = self::designer();
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $answer = self::upload($browser, self::UPLOAD_URL, $token, 'photo', $gif, 'animace.gif');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => CalendarAssetUploader::PHOTO_FORMAT], $answer);

        // An icon may still be one.
        self::upload($browser, self::UPLOAD_URL, $token, 'icon', $gif, 'ikona.gif');
        self::assertResponseIsSuccessful();
    }

    /**
     * A PDF of a few hundred bytes may announce a 5 × 5 m page; at the fixed
     * 200 dpi that was 40 000 px a side. The resolution now follows the page.
     */
    public function testAPdfWithAHugePageIsRasterisedToTheLongEdgeCap(): void
    {
        $browser = self::designer();
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $page = self::upload($browser, self::UPLOAD_URL, $token, 'page', self::pdfWithPage(14400, 7200), 'plachta.pdf');

        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $page['mime']);
        self::assertIsInt($page['width']);
        self::assertIsInt($page['height']);
        self::assertEqualsWithDelta(4000, $page['width'], 40);
        self::assertEqualsWithDelta(2000, $page['height'], 40);
    }

    public function testADocxThatUnpacksToTooMuchIsRefused(): void
    {
        $browser = self::designer();
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $file = (string) tempnam(sys_get_temp_dir(), 'calendar-docx-');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE);
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'
            . str_repeat('a', ExtractDocumentText::MAX_DOCUMENT_XML_BYTES + 1)
            . '</w:t></w:r></w:p></w:body></w:document>',
        );
        $zip->close();
        $bomb = (string) file_get_contents($file);
        unlink($file);

        self::assertLessThan(100_000, strlen($bomb), 'The archive itself is tiny.');

        $browser->request('POST', self::WORKSPACE_URL . '/document-text', ['_token' => $token], ['file' => self::file($bomb, 'bomba.docx')]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => ExtractDocumentText::TOO_LARGE], self::json($browser));
    }

    /**
     * PostgreSQL refuses a NUL in JSONB and in text: such a save used to end
     * as a 500 after the row lock. Control characters are stripped from
     * content, labels and names; a layout string carrying one is refused.
     */
    public function testControlCharactersNeverReachTheDatabase(): void
    {
        $browser = self::designer();
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $answer = self::save($browser, self::SAVE_URL, [
            'design' => ['name' => "Kalendář\u{0000} obce\u{0007}"],
            'content' => [
                ['c' => 'events', 'id' => 'eventNul', 'v' => self::event("Pouť\u{0000}\u{0001} u kaple"), 'label' => "1.\u{0000} 6.\u{001B}"],
                ['c' => 'specialPages', 'id' => 'textPage', 'v' => [
                    'kind' => 'text', 'on' => true, 'layout' => 0, 'position' => 'back', 'nextYear' => false, 'name' => "Slovo\u{0000}",
                    'asset' => null, 'text' => "První\u{0000} řádek\nDruhý\třádek\u{000B}", 'fileName' => "slovo\u{0000}.docx", 'pos' => 0,
                ]],
            ],
        ], $token);

        self::assertResponseIsSuccessful();
        self::assertSame('Kalendář obce', self::dig($answer, 'calendar', 'name'));
        self::assertSame('Pouť u kaple', self::dig($answer, 'calendar', 'content', 'events', 'eventNul', 'name'));
        // Line breaks and tabs of a multi-line text stay.
        self::assertSame("První řádek\nDruhý\třádek", self::dig($answer, 'calendar', 'content', 'specialPages', 'textPage', 'text'));
        self::assertSame('slovo.docx', self::dig($answer, 'calendar', 'content', 'specialPages', 'textPage', 'fileName'));

        foreach (self::history(TestDataFixture::CALENDAR_1_ID) as [, $sentence]) {
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $sentence);
        }

        // A style value is stored as it comes, so it is refused instead.
        /** @var array{styles: array<string, mixed>} $layout */
        $layout = json_decode(TestDataFixture::CALENDAR_LAYOUT_JSON, true, 64, JSON_THROW_ON_ERROR);
        $layout['styles']['note'] = "a\u{0000}b";

        $answer = self::save($browser, self::SAVE_URL, ['design' => ['preset' => 'photoLeft', 'layout' => $layout]], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertIsString($answer['error']);
    }

    public function testTheUploadedFileNameIsCleaned(): void
    {
        $browser = self::designer();
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        // Invalid UTF-8 (a lone Latin-2 byte) and a control character.
        $asset = self::upload($browser, self::UPLOAD_URL, $token, 'photo', self::jpeg(40, 20), "n\xE1ves\x01 jaro.jpg");

        self::assertResponseIsSuccessful();
        self::assertIsString($asset['name']);
        self::assertTrue(mb_check_encoding($asset['name'], 'UTF-8'));
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $asset['name']);
        self::assertStringEndsWith('jaro.jpg', $asset['name']);

        // The document-text endpoint echoes the name as well.
        $browser->request('POST', self::WORKSPACE_URL . '/document-text', ['_token' => $token], ['file' => self::file('Text', "slovo\xE1\x02.txt")]);
        self::assertResponseIsSuccessful();
        $name = self::json($browser)['name'];
        self::assertIsString($name);
        self::assertTrue(mb_check_encoding($name, 'UTF-8'));
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $name);
    }

    /**
     * The JavaScript looks content up in plain objects: an id like
     * `__proto__` must never become a key.
     */
    public function testIdsThatArePropertiesOfEveryJavaScriptObjectAreRefused(): void
    {
        $browser = self::designer();
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        foreach (['__proto__', 'constructor', 'prototype', 'toString', 'hasOwnProperty'] as $id) {
            $answer = self::save($browser, self::SAVE_URL, ['content' => [['c' => 'events', 'id' => $id, 'v' => self::event('Pouť')]]], $token);
            self::assertResponseStatusCodeSame(422, $id);
            self::assertSame(['error' => 'Neplatná data kalendáře (events › id).'], $answer, $id);

            // …nor a reference to one.
            $answer = self::save($browser, self::SAVE_URL, ['content' => [['c' => 'events', 'id' => 'eventOk', 'v' => ['club' => $id] + self::event('Pouť')]]], $token);
            self::assertResponseStatusCodeSame(422, $id);
        }

        $answer = self::save($browser, self::SAVE_URL, ['content' => [['c' => 'pageImages', 'id' => 'sp:__proto__', 'v' => ['head' => null, 'bg' => null]]]], $token);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(0, self::calendar(TestDataFixture::CALENDAR_1_ID)->revision);
    }

    /**
     * Besides the budget per link AND address there is one per link, whatever
     * the address — changing addresses must not multiply it.
     */
    public function testUploadsThroughTheLinkHaveABudgetPerLinkWhateverTheAddress(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $token = self::csrf(self::bootstrap($browser, self::CLIENT_URL));

        self::drain('limiter.calendar_client_upload_link', UploadClientCalendarAssetController::linkLimiterKey(TestDataFixture::CALENDAR_2_TOKEN), 300);

        foreach (['127.0.0.1', '203.0.113.7', '198.51.100.20'] as $address) {
            $browser->request('POST', self::CLIENT_URL . '/asset', ['_token' => $token, 'kind' => 'photo'], ['file' => self::file(self::jpeg(60, 40), 'foto.jpg')], ['REMOTE_ADDR' => $address]);
            self::assertResponseStatusCodeSame(429, $address);
            self::assertSame(['error' => UploadClientCalendarAssetController::TOO_MANY_UPLOADS], self::json($browser));
            self::assertGreaterThan(0, (int) $browser->getResponse()->headers->get('Retry-After'));
        }

        self::assertSame(0, self::storedAssets(TestDataFixture::CALENDAR_2_ID));

        // Another calendar's link is not affected (the CSRF token belongs to the session).
        self::upload($browser, '/k/' . TestDataFixture::CALENDAR_1_TOKEN . '/asset', $token, 'photo', self::jpeg(60, 40), 'foto.jpg');
        self::assertResponseIsSuccessful();
    }

    public function testClientUploadsOfOneCalendarHaveATotalSizeCap(): void
    {
        $browser = self::createClient();
        $token = self::csrf(self::bootstrap($browser, self::CLIENT_URL));

        // Two client photos of 1 GB each, as far as the bookkeeping goes.
        $connection = self::getContainer()->get(Connection::class);

        foreach (['00000000-0000-0000-0000-0000000001a1', '00000000-0000-0000-0000-0000000001a2'] as $id) {
            $connection->insert('calendar_asset', [
                'id' => $id,
                'calendar_id' => TestDataFixture::CALENDAR_2_ID,
                'kind' => 'photo',
                'path' => sprintf('calendars/%s/%s.jpg', TestDataFixture::CALENDAR_2_ID, $id),
                'mime_type' => 'image/jpeg',
                'original_name' => 'velka.jpg',
                'size_bytes' => intdiv(CalendarAssetUploader::MAX_CLIENT_BYTES, 2),
                'uploaded_by_kind' => 'client',
                'created_at' => '2026-11-01 10:00:00',
            ]);
        }

        $answer = self::upload($browser, self::CLIENT_URL . '/asset', $token, 'photo', self::jpeg(60, 40), 'dalsi.jpg');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => CalendarAssetUploader::CLIENT_STORAGE_FULL], $answer);
        self::assertSame(2, self::storedAssets(TestDataFixture::CALENDAR_2_ID));

        // The cap is the client's: the designer of the same calendar still uploads.
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $url = '/calendar/' . TestDataFixture::CALENDAR_2_ID;
        self::upload($browser, $url . '/asset', self::csrf(self::bootstrap($browser, $url)), 'photo', self::jpeg(60, 40), 'grafik.jpg');
        self::assertResponseIsSuccessful();
    }

    public function testSavingAndHistoryThroughTheLinkAreRateLimited(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $token = self::csrf(self::bootstrap($browser, self::CLIENT_URL));
        $payload = ['content' => [['c' => 'pageCaptions', 'id' => 'w05', 'v' => ['text' => 'Zima'], 'label' => '5. týden']]];

        self::save($browser, self::CLIENT_URL . '/save', $payload, $token);
        self::assertResponseIsSuccessful();
        $browser->request('GET', self::CLIENT_URL . '/history');
        self::assertResponseIsSuccessful();

        // Generous for a person: hundreds of saves an hour.
        self::drain('limiter.calendar_client_save', SaveClientCalendarController::limiterKey(TestDataFixture::CALENDAR_2_TOKEN), 300);

        $answer = self::save($browser, self::CLIENT_URL . '/save', ['content' => [['c' => 'pageCaptions', 'id' => 'w06', 'v' => ['text' => 'Mráz'], 'label' => '6. týden']]], $token);
        self::assertResponseStatusCodeSame(429);
        self::assertSame(['error' => SaveClientCalendarController::TOO_MANY_SAVES], $answer);
        self::assertGreaterThan(0, (int) $browser->getResponse()->headers->get('Retry-After'));
        self::assertSame(1, self::calendar(TestDataFixture::CALENDAR_2_ID)->revision);

        self::drain('limiter.calendar_client_history', ClientCalendarHistoryController::limiterKey(TestDataFixture::CALENDAR_2_TOKEN), 300);

        $browser->request('GET', self::CLIENT_URL . '/history');
        self::assertResponseStatusCodeSame(429);
        self::assertIsString(self::json($browser)['error']);

        // The designer's own endpoints of the same calendar have no such budget.
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $browser->request('GET', '/calendar/' . TestDataFixture::CALENDAR_2_ID . '/history');
        self::assertResponseIsSuccessful();
    }

    /**
     * Historie shows the newest 200 lines; the table keeps the newest
     * {@see CalendarHistoryWriter::MAX_ENTRIES} per calendar and no more.
     */
    public function testHistoryIsPrunedToTheNewestEntries(): void
    {
        $browser = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "INSERT INTO calendar_history (id, calendar_id, author_kind, description, created_at)
             SELECT gen_random_uuid(), :calendarId, 'client', 'Stará změna ' || n, TIMESTAMP '2026-01-01 00:00:00' + n * INTERVAL '1 minute'
             FROM generate_series(1, :count) AS n",
            ['calendarId' => TestDataFixture::CALENDAR_2_ID, 'count' => CalendarHistoryWriter::MAX_ENTRIES + 25],
        );

        $token = self::csrf(self::bootstrap($browser, self::CLIENT_URL));
        self::save($browser, self::CLIENT_URL . '/save', ['content' => [['c' => 'pageCaptions', 'id' => 'w05', 'v' => ['text' => 'Zima'], 'label' => '5. týden']]], $token);
        self::assertResponseIsSuccessful();

        $connection = self::getContainer()->get(Connection::class);
        $count = static function (string $sql) use ($connection): int {
            $value = $connection->fetchOne($sql);
            self::assertIsNumeric($value);

            return (int) $value;
        };
        $entries = static fn (string $calendarId, string $and = ''): int => $count(sprintf("SELECT COUNT(*) FROM calendar_history WHERE calendar_id = '%s' %s", $calendarId, $and));

        self::assertSame(CalendarHistoryWriter::MAX_ENTRIES, $entries(TestDataFixture::CALENDAR_2_ID));

        // The newest line is the one just written; the oldest ones went.
        self::assertStringContainsString('5. týden', self::history(TestDataFixture::CALENDAR_2_ID)[0][1]);
        self::assertSame(0, $entries(TestDataFixture::CALENDAR_2_ID, "AND description = 'Stará změna 1'"));

        // Other calendars are not touched.
        self::assertSame(1, $entries(TestDataFixture::CALENDAR_1_ID));
    }

    private static function designer(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        return $browser;
    }

    /**
     * Uses a limiter's budget up through the limiter itself.
     */
    private static function drain(string $service, string $key, int $atLeast): void
    {
        $factory = self::getContainer()->get($service);
        self::assertInstanceOf(\Symfony\Component\RateLimiter\RateLimiterFactoryInterface::class, $factory);
        $limiter = $factory->create($key);
        $spent = 0;

        while ($limiter->consume()->isAccepted()) {
            $spent++;
            self::assertLessThan(100_000, $spent, 'The limiter never refuses.');
        }

        self::assertGreaterThanOrEqual($atLeast, $spent, $service);
    }

    private static function storedAssets(string $calendarId): int
    {
        return count(self::getContainer()->get(CalendarAssetRepository::class)->forCalendar(self::calendar($calendarId)->id));
    }

    private static function file(string $bytes, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cal');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** Stored `$width × $height` with EXIF Orientation 6 — shown rotated a quarter turn. */
    private static function sidewaysJpeg(int $width, int $height): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('#7A93B8'));
        $image->setImageFormat('jpeg');
        $tiff = "II*\0" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, 6, 0) . pack('V', 0);
        $image->profileImage('exif', "Exif\0\0" . $tiff);
        $image->setImageOrientation(6);
        $bytes = $image->getImageBlob();
        $image->clear();

        return $bytes;
    }

    /**
     * A one-colour PNG written by hand (header + deflated rows), so the test
     * itself never holds the pixels ImageMagick would.
     */
    private static function flatPng(int $width, int $height): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        // 1-bit greyscale: one filter byte + width / 8 bytes per row.
        $row = "\0" . str_repeat("\0", intdiv($width + 7, 8));

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 1, 0, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress(str_repeat($row, $height), 9))
            . $chunk('IEND', '');
    }

    /** A valid one-page PDF with nothing on it; the page is `$width × $height` points. */
    private static function pdfWithPage(int $width, int $height): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] >>', $width, $height),
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $index + 1, $object);
        }

        $xref = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", count($objects) + 1);

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $xref);
    }
}
