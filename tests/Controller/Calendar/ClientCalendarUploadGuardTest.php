<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Calendar;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Controller\Calendar\UploadClientCalendarAssetController;
use WBoost\Web\Repository\CalendarAssetRepository;
use WBoost\Web\Services\Calendar\CalendarAssetUploader;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * Hardening of the PUBLIC upload endpoint of the client link
 * (`POST /k/{token}/asset`): raster photos only, and a budget per link and
 * address.
 *
 * @covers \WBoost\Web\Controller\Calendar\UploadClientCalendarAssetController
 * @covers \WBoost\Web\Value\Calendar\CalendarAssetKind
 */
final class ClientCalendarUploadGuardTest extends WebTestCase
{
    use CalendarTesting;

    private const string CLIENT_URL = '/k/' . TestDataFixture::CALENDAR_2_TOKEN;
    private const string ASSET_URL = self::CLIENT_URL . '/asset';
    private const string SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>alert(1)</script><rect width="10" height="10"/></svg>';

    public function testClientUploadsNothingButRasterPhotos(): void
    {
        $browser = self::createClient();
        $token = self::csrf(self::bootstrap($browser, self::CLIENT_URL));

        // Every kind but `photo` belongs to the designer.
        foreach (['icon', 'logo', 'qr', 'image', 'page'] as $kind) {
            $answer = self::upload($browser, self::ASSET_URL, $token, $kind, self::jpeg(60, 40), 'obrazek.jpg');
            self::assertResponseStatusCodeSame(403, $kind);
            self::assertSame(['error' => 'Tuto změnu může udělat jen grafik.'], $answer, $kind);
        }

        // An SVG is not a photo — neither under its own name nor dressed as a JPEG.
        foreach (['kresba.svg', 'foto.jpg'] as $name) {
            $answer = self::upload($browser, self::ASSET_URL, $token, 'photo', self::SVG, $name);
            self::assertResponseStatusCodeSame(422, $name);
            self::assertIsString($answer['error']);
        }

        // Neither is a file that is no picture at all.
        $answer = self::upload($browser, self::ASSET_URL, $token, 'photo', "<?php echo 'x';", 'foto.jpg');
        self::assertResponseStatusCodeSame(422);
        self::assertIsString($answer['error']);

        $answer = self::upload($browser, self::ASSET_URL, $token, 'photo', '%PDF-1.4 not a photo', 'foto.pdf');
        self::assertResponseStatusCodeSame(422);

        self::assertSame(0, self::storedAssets());

        // The real thing still goes through, and is stored as a raster.
        $photo = self::upload($browser, self::ASSET_URL, $token, 'photo', self::jpeg(60, 40), 'pout.jpg');
        self::assertResponseIsSuccessful();
        self::assertSame('photo', $photo['kind']);
        self::assertSame('image/jpeg', $photo['mime']);
        self::assertSame(60, $photo['width']);
        self::assertSame(1, self::storedAssets());
    }

    public function testReadOnlyMemberIsHeldToTheSameKinds(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::SHARED_USER_EMAIL);
        $url = '/calendar/' . TestDataFixture::CALENDAR_2_ID;
        $token = self::csrf(self::bootstrap($browser, $url));

        self::upload($browser, $url . '/asset', $token, 'icon', self::jpeg(60, 40), 'ikona.jpg');
        self::assertResponseStatusCodeSame(403);

        $answer = self::upload($browser, $url . '/asset', $token, 'photo', self::SVG, 'kresba.svg');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => CalendarAssetUploader::PHOTO_FORMAT], $answer);

        self::upload($browser, $url . '/asset', $token, 'photo', self::jpeg(60, 40), 'pout.jpg');
        self::assertResponseIsSuccessful();
    }

    /**
     * `disableReboot()` keeps one container — and with it the in-memory
     * limiter storage of the test environment — alive across the requests
     * (config/packages/test/rate_limiter.php). The budget is used up through
     * the limiter itself instead of 200 real uploads.
     */
    public function testUploadsThroughTheLinkAreRateLimitedPerLinkAndAddress(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $token = self::csrf(self::bootstrap($browser, self::CLIENT_URL));

        self::upload($browser, self::ASSET_URL, $token, 'photo', self::jpeg(60, 40), 'prvni.jpg');
        self::assertResponseIsSuccessful();

        $limiter = self::getContainer()->get('limiter.calendar_client_upload')->create(UploadClientCalendarAssetController::limiterKey(TestDataFixture::CALENDAR_2_TOKEN, '127.0.0.1'));

        $spent = 1;

        while ($limiter->consume()->isAccepted()) {
            $spent++;
            self::assertLessThan(10_000, $spent, 'The limiter never refuses.');
        }

        // A sensible budget for a clerk filling a whole year of photos.
        self::assertGreaterThanOrEqual(120, $spent);

        $answer = self::upload($browser, self::ASSET_URL, $token, 'photo', self::jpeg(60, 40), 'navic.jpg');
        self::assertResponseStatusCodeSame(429);
        self::assertSame(['error' => UploadClientCalendarAssetController::TOO_MANY_UPLOADS], $answer);
        self::assertGreaterThan(0, (int) $browser->getResponse()->headers->get('Retry-After'));
        self::assertSame(1, self::storedAssets());

        // Another address holding the same link has its own budget…
        $browser->request('POST', self::ASSET_URL, ['_token' => $token, 'kind' => 'photo'], ['file' => self::file(self::jpeg(60, 40), 'jinde.jpg')], ['REMOTE_ADDR' => '203.0.113.7']);
        self::assertResponseIsSuccessful();

        // …and saving content is not affected by the upload budget.
        self::save($browser, self::CLIENT_URL . '/save', ['content' => [['c' => 'pageCaptions', 'id' => 'w05', 'v' => ['text' => 'Zima'], 'label' => '5. týden']]], $token);
        self::assertResponseIsSuccessful();
    }

    public function testAnUnknownLinkIsNotFoundAndNotCounted(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/k/' . str_repeat('ab', 32) . '/asset', ['_token' => 'x', 'kind' => 'photo']);

        self::assertResponseStatusCodeSame(404);
    }

    private static function storedAssets(): int
    {
        return count(self::getContainer()->get(CalendarAssetRepository::class)->forCalendar(self::calendar(TestDataFixture::CALENDAR_2_ID)->id));
    }

    private static function file(string $bytes, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cal');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }
}
