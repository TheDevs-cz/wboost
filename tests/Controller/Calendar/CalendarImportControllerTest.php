<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Calendar;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Repository\CalendarAssetRepository;
use WBoost\Web\Services\Calendar\CalendarResponses;
use WBoost\Web\Services\Calendar\CalendarTableReader;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * „Nahrát z Excelu“ (K10b): the table endpoint for the designer and the
 * client link, the „Stáhnout vzor“ download — and that nothing is stored.
 *
 * @covers \WBoost\Web\Controller\Calendar\CalendarImportController
 * @covers \WBoost\Web\Controller\Calendar\ClientCalendarImportController
 * @covers \WBoost\Web\Services\Calendar\CalendarImportHttp
 * @covers \WBoost\Web\Services\Calendar\CalendarImportTemplate
 */
final class CalendarImportControllerTest extends WebTestCase
{
    use CalendarTesting;

    private const string FIXTURES = __DIR__ . '/../../Fixtures/calendar/import/';
    private const string WORKSPACE_URL = '/calendar/' . TestDataFixture::CALENDAR_1_ID;
    private const string CLIENT_URL = '/k/' . TestDataFixture::CALENDAR_2_TOKEN;

    public function testTheDesignerGetsTheRowsOfAnUploadedTableAndNothingIsStored(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $bootstrap = self::bootstrap($browser, self::WORKSPACE_URL);
        self::assertSame(self::WORKSPACE_URL . '/import/table', self::dig($bootstrap, 'env', 'urls', 'importTable'));
        self::assertSame(self::WORKSPACE_URL . '/import/template/waste', self::dig($bootstrap, 'env', 'urls', 'importTemplateWaste'));
        self::assertSame(self::WORKSPACE_URL . '/import/template/events', self::dig($bootstrap, 'env', 'urls', 'importTemplateEvents'));

        $answer = self::table($browser, self::WORKSPACE_URL . '/import/table', self::csrf($bootstrap), (string) file_get_contents(self::FIXTURES . 'odpady-radky.xlsx'), 'C:\\obec\\odpady-radky.xlsx');

        self::assertResponseIsSuccessful();
        self::assertSame(['sheets', 'truncated', 'name'], array_keys($answer));
        self::assertSame('odpady-radky.xlsx', $answer['name']);
        self::assertSame(['2027-01-07', 'Komunální odpad'], self::dig($answer, 'sheets', 0, 'rows', 3));
        self::assertSame(0, self::calendar(TestDataFixture::CALENDAR_1_ID)->revision, 'reading a table does not touch the calendar');
        self::assertCount(0, self::getContainer()->get(CalendarAssetRepository::class)->forCalendar(self::calendar(TestDataFixture::CALENDAR_1_ID)->id), 'nor stores the file');
    }

    public function testTheClientLinkMayImportToo(): void
    {
        $browser = self::createClient();
        $bootstrap = self::bootstrap($browser, self::CLIENT_URL);
        self::assertSame(self::CLIENT_URL . '/import/table', self::dig($bootstrap, 'env', 'urls', 'importTable'));
        $assets = count(self::getContainer()->get(CalendarAssetRepository::class)->forCalendar(self::calendar(TestDataFixture::CALENDAR_2_ID)->id));

        $answer = self::table($browser, self::CLIENT_URL . '/import/table', self::csrf($bootstrap), (string) file_get_contents(self::FIXTURES . 'akce-1250.csv'), 'akce.csv');

        self::assertResponseIsSuccessful();
        self::assertSame(['6.3.2027', 'Maškarní ples; pro děti', 'ZŠ Testov'], self::dig($answer, 'sheets', 0, 'rows', 1));
        self::assertCount($assets, self::getContainer()->get(CalendarAssetRepository::class)->forCalendar(self::calendar(TestDataFixture::CALENDAR_2_ID)->id));
    }

    public function testRefusals(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        self::table($browser, self::WORKSPACE_URL . '/import/table', 'wrong', 'Datum;Druh', 'a.csv');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => CalendarResponses::CSRF_EXPIRED], self::json($browser));

        self::assertSame(['error' => CalendarTableReader::UNREADABLE], self::table($browser, self::WORKSPACE_URL . '/import/table', $token, '%PDF-1.4', 'svoz.pdf'));
        self::assertResponseStatusCodeSame(422);

        $browser->request('POST', self::WORKSPACE_URL . '/import/table', ['_token' => $token]);
        self::assertResponseStatusCodeSame(400);

        // another user's calendar
        $browser->getCookieJar()->clear();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_2_EMAIL);
        $browser->request('POST', self::WORKSPACE_URL . '/import/table', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);

        // an unknown client token is a 404
        $browser->request('POST', '/k/' . str_repeat('0', 64) . '/import/table', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testStahnoutVzorIsAnXlsxWithTheCalendarsYearKindsAndClubsThatTheImportReadsBack(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $waste = self::download($browser, self::WORKSPACE_URL . '/import/template/waste', 'odpady-vzor-2027.xlsx');
        $sheets = (new CalendarTableReader())->read($waste, 'vzor.xlsx')['sheets'];
        self::assertSame(['Odpady', 'Seznamy', 'Návod'], array_column($sheets, 'name'));
        self::assertSame(['Datum', 'Druh odpadu', 'Místo svozu'], $sheets[0]['rows'][0]);
        self::assertSame('2027-01-07', $sheets[0]['rows'][1][0], 'example dates are real date cells of the calendar year');
        self::assertContains('Komunální odpad', array_column($sheets[1]['rows'], 0), 'the calendar\'s kinds are offered');
        self::assertStringContainsString('1. 12. 2026', implode(' ', array_merge(...$sheets[2]['rows'])));

        $events = self::download($browser, self::WORKSPACE_URL . '/import/template/events', 'akce-vzor-2027.xlsx');
        $sheets = (new CalendarTableReader())->read($events, 'vzor.xlsx')['sheets'];
        self::assertSame(['Datum', 'Název akce', 'Zkratka spolku', 'Název spolku'], $sheets[0]['rows'][0]);
        self::assertSame('2027-02-14', $sheets[0]['rows'][1][0]);

        $browser->request('GET', self::WORKSPACE_URL . '/import/template/other');
        self::assertResponseStatusCodeSame(404);

        // the client link downloads it too
        $browser->getCookieJar()->clear();
        self::download($browser, self::CLIENT_URL . '/import/template/events', 'akce-vzor-2027.xlsx');
    }

    /**
     * @return array<string, mixed>
     */
    private static function table(KernelBrowser $browser, string $url, string $token, string $bytes, string $name): array
    {
        $file = tempnam(sys_get_temp_dir(), 'calendar-import-');
        self::assertIsString($file);
        file_put_contents($file, $bytes);
        $browser->request('POST', $url, ['_token' => $token], ['file' => new UploadedFile($file, $name, null, null, true)]);

        return self::json($browser);
    }

    /** @return string path of the downloaded file */
    private static function download(KernelBrowser $browser, string $url, string $fileName): string
    {
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $response = $browser->getResponse();
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        self::assertStringContainsString($fileName, (string) $response->headers->get('Content-Disposition'));
        $path = tempnam(sys_get_temp_dir(), 'vzor-');
        self::assertIsString($path);
        file_put_contents($path, (string) $response->getContent());

        return $path;
    }
}
