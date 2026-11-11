<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Calendar;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Controller\Calendar\ClientCalendarExportController;
use WBoost\Web\Entity\CalendarExport;
use WBoost\Web\Exceptions\CalendarExportNeedsMoreSpace;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Repository\CalendarExportRepository;
use WBoost\Web\Services\Calendar\CalendarExportHttp;
use WBoost\Web\Services\Calendar\CalendarExports;
use WBoost\Web\Services\Calendar\CalendarPdfExporter;
use WBoost\Web\Services\Calendar\CalendarResponses;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeCalendarPdfProducer;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\Calendar\CalendarAuthorKind;
use WBoost\Web\Value\Calendar\CalendarExportStatus;
use WBoost\Web\Value\Calendar\CalendarExportVariant;

/**
 * „Stáhnout PDF“ — both route families, every role, the job's life (reuse by
 * revision, failure, a stalled job, pruning), the binding of an export id to
 * its calendar and the budget of the public link.
 *
 * The export job is routed `sync` in tests and the page machinery is
 * {@see FakeCalendarPdfProducer}, so a POST answers the finished export.
 *
 * @covers \WBoost\Web\Controller\Calendar\CalendarExportController
 * @covers \WBoost\Web\Controller\Calendar\ClientCalendarExportController
 * @covers \WBoost\Web\Services\Calendar\CalendarExportHttp
 * @covers \WBoost\Web\Services\Calendar\CalendarExports
 * @covers \WBoost\Web\Services\Calendar\CalendarPdfExporter
 * @covers \WBoost\Web\MessageHandler\Calendar\RequestCalendarExportHandler
 * @covers \WBoost\Web\MessageHandler\Calendar\RunCalendarExportHandler
 */
final class CalendarExportControllerTest extends WebTestCase
{
    use CalendarTesting;

    private const string WORKSPACE_URL = '/calendar/' . TestDataFixture::CALENDAR_2_ID;
    private const string EXPORT_URL = self::WORKSPACE_URL . '/export';
    private const string CLIENT_URL = '/k/' . TestDataFixture::CALENDAR_2_TOKEN;
    private const string CLIENT_EXPORT_URL = self::CLIENT_URL . '/export';
    private const string OTHER_WORKSPACE_URL = '/calendar/' . TestDataFixture::CALENDAR_3_ID;

    protected function setUp(): void
    {
        FakeCalendarPdfProducer::reset();
    }

    public function testDesignerExportsForPrintAndDownloadsTheFile(): void
    {
        $browser = self::designer();
        $bootstrap = self::bootstrap($browser, self::WORKSPACE_URL);
        self::assertSame(self::EXPORT_URL, self::dig($bootstrap, 'env', 'urls', 'export'));

        $export = self::request($browser, self::EXPORT_URL, 'print', self::csrf($bootstrap));

        self::assertResponseIsSuccessful();
        self::assertSame('print', $export['variant']);
        self::assertSame('done', $export['status']);
        self::assertSame(FakeCalendarPdfProducer::PAGES, $export['done']);
        self::assertSame(FakeCalendarPdfProducer::PAGES, $export['total']);
        self::assertNull($export['error']);
        self::assertSame('Obecní kalendář - tisk.pdf', $export['fileName']);
        self::assertIsInt($export['sizeBytes']);
        self::assertSame(
            ['status' => self::EXPORT_URL . '/' . $export['id'], 'download' => self::EXPORT_URL . '/' . $export['id'] . '/download'],
            $export['urls'],
        );

        // The row says what the file was made from, the file lies under the calendar's own directory.
        $row = self::export($export['id']);
        self::assertSame(0, $row->calendarRevision);
        self::assertSame(CalendarAuthorKind::Designer, $row->requestedByKind);
        self::assertSame(TestDataFixture::USER_1_EMAIL, $row->requestedBy?->email);
        self::assertMatchesRegularExpression('~^calendars/' . TestDataFixture::CALENDAR_2_ID . '/exports/[0-9a-f]{40}\.pdf$~', (string) $row->path);
        self::assertTrue(self::storage()->fileExists((string) $row->path));

        $browser->request('GET', $export['urls']['status']);
        self::assertResponseIsSuccessful();
        self::assertSame($export, self::json($browser)['export']);

        $browser->request('GET', (string) $export['urls']['download']);
        self::assertResponseIsSuccessful();
        $response = $browser->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF-', (string) $response->getContent());
        self::assertStringContainsString('fake ' . TestDataFixture::CALENDAR_2_ID . ' print r0', (string) $response->getContent());
        self::assertSame($export['sizeBytes'], strlen((string) $response->getContent()));
        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment; filename="Obecni kalendar - tisk.pdf"', $disposition);
        self::assertStringContainsString("filename*=utf-8''Obecn%C3%AD%20kalend%C3%A1%C5%99%20-%20tisk.pdf", $disposition);
        // a buffered Response — never a stream (FrankenPHP)
        self::assertNotInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $response);

        self::assertSame([['Grafik', 'Stáhl PDF pro tisk']], self::history(TestDataFixture::CALENDAR_2_ID));
    }

    public function testTheApprovalVariantIsAFileOfItsOwn(): void
    {
        $browser = self::designer();
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $print = self::request($browser, self::EXPORT_URL, 'print', $token);
        $approval = self::request($browser, self::EXPORT_URL, 'approval', $token);

        self::assertNotSame($print['id'], $approval['id']);
        self::assertSame('Obecní kalendář - náhled.pdf', $approval['fileName']);

        $browser->request('GET', (string) $approval['urls']['download']);
        self::assertStringContainsString('approval r0', (string) $browser->getResponse()->getContent());

        self::assertSame(
            [['Grafik', 'Stáhl PDF k odsouhlasení'], ['Grafik', 'Stáhl PDF pro tisk']],
            self::history(TestDataFixture::CALENDAR_2_ID),
        );
    }

    public function testRefusals(): void
    {
        $browser = self::designer();
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $browser->request('POST', self::EXPORT_URL, ['_token' => 'nope', 'variant' => 'print']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => CalendarResponses::CSRF_EXPIRED], self::json($browser));

        $browser->request('POST', self::EXPORT_URL, ['_token' => $token, 'variant' => 'cmyk']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => CalendarExportHttp::UNKNOWN_VARIANT], self::json($browser));

        // A calendar without a layout has nothing to print.
        $browser->request('POST', '/calendar/' . TestDataFixture::CALENDAR_1_ID . '/export', ['_token' => $token, 'variant' => 'print']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => CalendarExports::NO_LAYOUT], self::json($browser));

        self::assertSame(0, self::exportCount(TestDataFixture::CALENDAR_2_ID));
        self::assertSame(0, self::exportCount(TestDataFixture::CALENDAR_1_ID));
        self::assertSame([], self::history(TestDataFixture::CALENDAR_2_ID));

        // Not logged in → the login page; a stranger to the project → 403.
        self::ensureKernelShutdown();
        $anonymous = self::createClient();
        $anonymous->request('POST', self::EXPORT_URL, ['_token' => $token, 'variant' => 'print']);
        self::assertResponseRedirects();

        self::ensureKernelShutdown();
        $stranger = self::createClient();
        TestingLogin::logInAsUser($stranger, TestDataFixture::USER_2_EMAIL);
        $stranger->request('POST', self::EXPORT_URL, ['_token' => 'x', 'variant' => 'print']);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Whoever may open the calendar may export it — the read-only member
     * too; it is recorded as the client's.
     */
    public function testAReadOnlyMemberMayExport(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::SHARED_USER_EMAIL);
        $bootstrap = self::bootstrap($browser, self::WORKSPACE_URL);
        self::assertSame('client', $bootstrap['mode']);

        $export = self::request($browser, self::EXPORT_URL, 'print', self::csrf($bootstrap));

        self::assertSame('done', $export['status']);
        // a member keeps the /calendar/{id} addresses and never learns the token
        self::assertStringStartsWith(self::EXPORT_URL . '/', (string) $export['urls']['download']);
        self::assertSame(CalendarAuthorKind::Client, self::export($export['id'])->requestedByKind);
        self::assertSame([['Klient', 'Stáhl PDF pro tisk']], self::history(TestDataFixture::CALENDAR_2_ID));

        $browser->request('GET', (string) $export['urls']['download']);
        self::assertResponseIsSuccessful();
    }

    public function testAnUnchangedCalendarIsAnsweredWithTheSameExportAndAChangedOneIsRenderedAnew(): void
    {
        $browser = self::designer();
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $first = self::request($browser, self::EXPORT_URL, 'print', $token);
        $again = self::request($browser, self::EXPORT_URL, 'print', $token);

        self::assertSame($first['id'], $again['id']);
        self::assertSame(1, self::exportCount(TestDataFixture::CALENDAR_2_ID));
        // …but every request is somebody taking the file: two history lines.
        self::assertCount(2, self::history(TestDataFixture::CALENDAR_2_ID));

        // A save moves the revision: the old file no longer shows the calendar.
        self::save($browser, self::WORKSPACE_URL . '/save', ['content' => [['c' => 'pageCaptions', 'id' => 'w05', 'v' => ['text' => 'Nový popisek'], 'label' => '5. týden']]], $token);
        self::assertResponseIsSuccessful();

        $third = self::request($browser, self::EXPORT_URL, 'print', $token);

        self::assertNotSame($first['id'], $third['id']);
        self::assertSame(1, self::export($third['id'])->calendarRevision);
        $browser->request('GET', (string) $third['urls']['download']);
        self::assertStringContainsString('print r1', (string) $browser->getResponse()->getContent());

        // The older file stays downloadable until it is pruned.
        $browser->request('GET', (string) $first['urls']['download']);
        self::assertStringContainsString('print r0', (string) $browser->getResponse()->getContent());
    }

    public function testOnlyTheNewestExportsAreKeptAndTheirFilesGoWithThem(): void
    {
        $browser = self::designer();
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));
        $paths = [];

        for ($round = 0; $round < CalendarPdfExporter::KEEP + 2; ++$round) {
            $export = self::request($browser, self::EXPORT_URL, 'print', $token);
            $paths[] = (string) self::export($export['id'])->path;
            self::touch($browser, self::WORKSPACE_URL . '/save', $token, $round);
        }

        self::assertSame(CalendarPdfExporter::KEEP, self::exportCount(TestDataFixture::CALENDAR_2_ID));

        foreach ($paths as $index => $path) {
            self::assertSame($index >= 2, self::storage()->fileExists($path), sprintf('export %d', $index));
        }
    }

    public function testAFailedJobIsReportedWithACzechSentenceAndTheNextRequestStartsANewOne(): void
    {
        $browser = self::designer();
        $browser->disableReboot();
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        FakeCalendarPdfProducer::$failWith = new \RuntimeException('Chromium said no.');
        $failed = self::request($browser, self::EXPORT_URL, 'print', $token);

        self::assertResponseIsSuccessful();
        self::assertSame('failed', $failed['status']);
        self::assertSame(CalendarExports::FAILED, $failed['error']);
        self::assertNull($failed['urls']['download']);
        self::assertStringNotContainsString('Chromium', (string) json_encode($failed));

        $browser->request('GET', $failed['urls']['status']);
        self::assertSame('failed', self::dig(self::json($browser), 'export', 'status'));

        $browser->request('GET', self::EXPORT_URL . '/' . $failed['id'] . '/download');
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => CalendarExportHttp::NOT_READY], self::json($browser));

        // A busy renderer has a sentence of its own.
        FakeCalendarPdfProducer::$failWith = TemplateRenderUnavailable::timedOut(new \RuntimeException('timeout'));
        $busy = self::request($browser, self::EXPORT_URL, 'print', $token);
        self::assertSame('failed', $busy['status']);
        self::assertSame(CalendarExports::BUSY, $busy['error']);

        // No room for a file this large: said before anything is rendered, with what to do instead.
        FakeCalendarPdfProducer::$failWith = new CalendarExportNeedsMoreSpace(1_300_000_000, 400_000_000);
        $full = self::request($browser, self::EXPORT_URL, 'print', $token);
        self::assertSame('failed', $full['status']);
        self::assertSame(CalendarExports::NO_SPACE, $full['error']);

        // A failed export answers nothing: the retry is a new job.
        $retry = self::request($browser, self::EXPORT_URL, 'print', $token);
        self::assertSame('done', $retry['status']);
        self::assertNotContains($retry['id'], [$failed['id'], $busy['id'], $full['id']]);
        self::assertCount(4, FakeCalendarPdfProducer::$calls);
    }

    /**
     * A job the consumer never finished (restarted mid-export, or not
     * running) reads as failed once it has been silent too long, and no
     * longer blocks a new one.
     */
    public function testAStalledJobReadsAsFailedAndIsNotReused(): void
    {
        $browser = self::designer();
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $calendar = self::calendar(TestDataFixture::CALENDAR_2_ID);
        $stuck = new CalendarExport(
            Uuid::uuid7(),
            $calendar,
            CalendarExportVariant::Print,
            $calendar->revision,
            self::getContainer()->get(\WBoost\Web\Services\Calendar\CalendarModuleInliner::class)->fingerprint(),
            CalendarAuthorKind::Designer,
            null,
            new \DateTimeImmutable(sprintf('-%d seconds', CalendarExport::STALE_AFTER_SECONDS + 60)),
        );
        $entityManager->persist($stuck);
        $entityManager->flush();

        $browser->request('GET', self::EXPORT_URL . '/' . $stuck->id->toString());
        $read = self::json($browser)['export'] ?? null;
        self::assertIsArray($read);
        self::assertSame('failed', $read['status']);
        self::assertSame(CalendarExports::STALLED, $read['error']);

        $fresh = self::request($browser, self::EXPORT_URL, 'print', $token);
        self::assertNotSame($stuck->id->toString(), $fresh['id']);
        self::assertSame('done', $fresh['status']);
    }

    /**
     * A print file of a full year is hundreds of MB: above the buffered
     * limit the download is a redirect to the storage object (unguessable
     * key, stored with its own download name).
     */
    public function testALargeFileIsDownloadedStraightFromTheStorage(): void
    {
        $browser = self::designer();
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));
        $export = self::request($browser, self::EXPORT_URL, 'print', $token);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $row = self::export($export['id']);
        $row->sizeBytes = CalendarExports::BUFFERED_DOWNLOAD_BYTES + 1;
        $entityManager->flush();

        $browser->request('GET', (string) $export['urls']['download']);

        self::assertResponseStatusCodeSame(302);
        self::assertStringEndsWith('/' . $row->path, (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testTheClientLinkExportsAndAnExportIsBoundToItsCalendar(): void
    {
        $browser = self::createClient();
        $bootstrap = self::bootstrap($browser, self::CLIENT_URL);
        self::assertSame(self::CLIENT_EXPORT_URL, self::dig($bootstrap, 'env', 'urls', 'export'));
        $token = self::csrf($bootstrap);

        $export = self::request($browser, self::CLIENT_EXPORT_URL, 'print', $token);

        self::assertSame('done', $export['status']);
        self::assertSame(
            ['status' => self::CLIENT_EXPORT_URL . '/' . $export['id'], 'download' => self::CLIENT_EXPORT_URL . '/' . $export['id'] . '/download'],
            $export['urls'],
        );
        // the link never names the calendar's uuid
        self::assertStringNotContainsString(TestDataFixture::CALENDAR_2_ID, (string) $browser->getResponse()->getContent());
        self::assertSame(CalendarAuthorKind::Client, self::export($export['id'])->requestedByKind);
        self::assertNull(self::export($export['id'])->requestedBy);
        self::assertSame([['Klient', 'Stáhl PDF pro tisk']], self::history(TestDataFixture::CALENDAR_2_ID));

        $browser->request('GET', (string) $export['urls']['download']);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF-', (string) $browser->getResponse()->getContent());

        $browser->request('POST', self::CLIENT_EXPORT_URL, ['_token' => 'nope', 'variant' => 'print']);
        self::assertResponseStatusCodeSame(403);

        // An unknown link is a 404 on all three endpoints.
        $unknown = '/k/' . str_repeat('ab', 32) . '/export';
        $browser->request('POST', $unknown, ['_token' => $token, 'variant' => 'print']);
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', $unknown . '/' . $export['id']);
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', $unknown . '/' . $export['id'] . '/download');
        self::assertResponseStatusCodeSame(404);

        // An export of ANOTHER calendar, made by somebody who may see it…
        $designer = self::designerOf(TestDataFixture::USER_2_EMAIL);
        $foreign = self::request($designer, self::OTHER_WORKSPACE_URL . '/export', 'print', self::csrf(self::bootstrap($designer, self::OTHER_WORKSPACE_URL)));
        self::assertSame('done', $foreign['status']);

        // …is not readable through this link,
        self::ensureKernelShutdown();
        $browser = self::createClient();
        foreach (['', '/download'] as $suffix) {
            $browser->request('GET', self::CLIENT_EXPORT_URL . '/' . $foreign['id'] . $suffix);
            self::assertResponseStatusCodeSame(404, $suffix);
        }

        // nor through the other link with THIS calendar's export id,
        $browser->request('GET', '/k/' . TestDataFixture::CALENDAR_3_TOKEN . '/export/' . $export['id'] . '/download');
        self::assertResponseStatusCodeSame(404);

        // nor through a calendar address its reader may open.
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        foreach (['', '/download'] as $suffix) {
            $browser->request('GET', self::EXPORT_URL . '/' . $foreign['id'] . $suffix);
            self::assertResponseStatusCodeSame(404, $suffix);
        }
        $browser->request('GET', self::EXPORT_URL . '/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The link needs no account and a render is minutes of Chromium: ten NEW
     * renders per hour per link. A request the existing file answers costs
     * nothing — the same PDF can always be downloaded again.
     */
    public function testNewRendersThroughTheLinkHaveABudgetButAnExistingFileIsAlwaysHandedOut(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $token = self::csrf(self::bootstrap($browser, self::CLIENT_URL));

        for ($round = 1; $round <= 10; ++$round) {
            $export = self::request($browser, self::CLIENT_EXPORT_URL, 'approval', $token);
            self::assertSame('done', $export['status'], (string) $round);
            // asking again is free
            self::assertSame($export['id'], self::request($browser, self::CLIENT_EXPORT_URL, 'approval', $token)['id']);
            self::touch($browser, self::CLIENT_URL . '/save', $token, $round);
        }

        self::assertCount(10, FakeCalendarPdfProducer::$calls);

        $browser->request('POST', self::CLIENT_EXPORT_URL, ['_token' => $token, 'variant' => 'approval']);
        self::assertResponseStatusCodeSame(429);
        self::assertSame(['error' => ClientCalendarExportController::TOO_MANY_EXPORTS], self::json($browser));
        self::assertGreaterThan(0, (int) $browser->getResponse()->headers->get('Retry-After'));
        // a refused request is not "somebody took the file"
        self::assertCount(20, array_filter(self::history(TestDataFixture::CALENDAR_2_ID), static fn (array $line): bool => $line[1] === 'Stáhl PDF k odsouhlasení'));

        // The other link has its own budget; the designer has none.
        $other = self::request($browser, '/k/' . TestDataFixture::CALENDAR_3_TOKEN . '/export', 'approval', $token);
        self::assertSame('done', $other['status']);

        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));
        self::assertSame('done', self::request($browser, self::EXPORT_URL, 'approval', $token)['status']);
    }

    /**
     * The dialog asks what is there when it opens: a job on its way (whoever
     * started it) is picked up, a finished file offered. And a second request
     * for a variant that is being prepared joins that job — two people asking
     * at the same time share ONE render.
     */
    public function testARunningJobIsFoundAndSharedAndAFinishedFileIsOffered(): void
    {
        $browser = self::designer();
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $browser->request('GET', self::EXPORT_URL);
        self::assertResponseIsSuccessful();
        self::assertSame('{"exports":{"print":null,"approval":null}}', $browser->getResponse()->getContent());

        // Somebody's print export is on its way (queued a moment ago, the consumer has it).
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $calendar = self::calendar(TestDataFixture::CALENDAR_2_ID);
        $running = new CalendarExport(
            Uuid::uuid7(),
            $calendar,
            CalendarExportVariant::Print,
            $calendar->revision,
            self::getContainer()->get(\WBoost\Web\Services\Calendar\CalendarModuleInliner::class)->fingerprint(),
            CalendarAuthorKind::Client,
            null,
            new \DateTimeImmutable('-20 seconds'),
        );
        $running->start(55, $calendar->revision, new \DateTimeImmutable('-15 seconds'));
        $running->advance(12, new \DateTimeImmutable('-1 second'));
        $entityManager->persist($running);
        $entityManager->flush();

        $browser->request('GET', self::EXPORT_URL);
        $current = self::json($browser);
        self::assertNull(self::dig($current, 'exports', 'approval'));
        self::assertSame($running->id->toString(), self::dig($current, 'exports', 'print', 'id'));
        self::assertSame(
            ['running', 12, 55],
            [self::dig($current, 'exports', 'print', 'status'), self::dig($current, 'exports', 'print', 'done'), self::dig($current, 'exports', 'print', 'total')],
        );

        // The designer asks for the same variant: the same job, no second render.
        $joined = self::request($browser, self::EXPORT_URL, 'print', $token);
        self::assertSame($running->id->toString(), $joined['id']);
        self::assertSame('running', $joined['status']);
        self::assertSame([], FakeCalendarPdfProducer::$calls);
        self::assertSame(1, self::exportCount(TestDataFixture::CALENDAR_2_ID));

        // …and so does the holder of the client link, who sees it through the link's own addresses.
        self::ensureKernelShutdown();
        $client = self::createClient();
        $clientToken = self::csrf(self::bootstrap($client, self::CLIENT_URL));
        $client->request('GET', self::CLIENT_EXPORT_URL);
        self::assertSame(self::CLIENT_EXPORT_URL . '/' . $running->id->toString(), self::dig(self::json($client), 'exports', 'print', 'urls', 'status'));
        self::assertSame($running->id->toString(), self::request($client, self::CLIENT_EXPORT_URL, 'print', $clientToken)['id']);
        self::assertSame([], FakeCalendarPdfProducer::$calls);

        // The other variant is free: it is rendered, and from then on offered as a finished file.
        $approval = self::request($client, self::CLIENT_EXPORT_URL, 'approval', $clientToken);
        self::assertSame('done', $approval['status']);
        $client->request('GET', self::CLIENT_EXPORT_URL);
        self::assertSame($approval['id'], self::dig(self::json($client), 'exports', 'approval', 'id'));
        self::assertIsString(self::dig(self::json($client), 'exports', 'approval', 'urls', 'download'));

        $client->request('GET', '/k/' . str_repeat('ab', 32) . '/export');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeletingTheCalendarRemovesItsExports(): void
    {
        $browser = self::designer();
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));
        $export = self::request($browser, self::EXPORT_URL, 'print', $token);
        $path = (string) self::export($export['id'])->path;
        self::assertTrue(self::storage()->fileExists($path));

        $browser->request('POST', self::WORKSPACE_URL . '/delete', ['_token' => $token]);
        self::assertResponseRedirects();

        self::assertFalse(self::storage()->fileExists($path));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull(self::getContainer()->get(EntityManagerInterface::class)->find(CalendarExport::class, Uuid::fromString($export['id'])));
    }

    /**
     * @return array{id: string, variant: string, status: string, done: int, total: int, error: null|string, sizeBytes: null|int, fileName: string, urls: array{status: string, download: null|string}}
     */
    private static function request(KernelBrowser $browser, string $url, string $variant, string $token): array
    {
        $browser->request('POST', $url, ['_token' => $token, 'variant' => $variant]);
        self::assertResponseIsSuccessful((string) $browser->getResponse()->getContent());
        $export = self::json($browser)['export'] ?? null;
        self::assertIsArray($export);

        /** @var array{id: string, variant: string, status: string, done: int, total: int, error: null|string, sizeBytes: null|int, fileName: string, urls: array{status: string, download: null|string}} $export */
        return $export;
    }

    /** A save that changes something — the calendar's revision moves on. */
    private static function touch(KernelBrowser $browser, string $saveUrl, string $token, int $round): void
    {
        self::save($browser, $saveUrl, ['content' => [['c' => 'pageCaptions', 'id' => 'w05', 'v' => ['text' => 'Popisek ' . $round], 'label' => '5. týden']]], $token);
        self::assertResponseIsSuccessful();
    }

    private static function export(string $id): CalendarExport
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $export = $entityManager->find(CalendarExport::class, Uuid::fromString($id));
        self::assertInstanceOf(CalendarExport::class, $export);

        return $export;
    }

    private static function exportCount(string $calendarId): int
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return count(self::getContainer()->get(CalendarExportRepository::class)->forCalendar(Uuid::fromString($calendarId)));
    }

    private static function storage(): FilesystemOperator
    {
        return self::getContainer()->get(Filesystem::class);
    }

    private static function designer(): KernelBrowser
    {
        return self::designerOf(TestDataFixture::USER_1_EMAIL);
    }

    private static function designerOf(string $email): KernelBrowser
    {
        self::ensureKernelShutdown();
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, $email);

        return $browser;
    }
}
