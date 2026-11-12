<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\AiScan;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Sentry\Event;
use WBoost\Web\Services\AiScan\AiScanSentryScrubber;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * The admin's per-project switch on the edit-project page, the console
 * commands, and the rule that nothing of a scan's content reaches a log.
 */
final class AiScanAdminSwitchAndLogsTest extends WebTestCase
{
    use AiScanTesting;

    private const string PROJECT = TestDataFixture::PROJECT_1_ID;
    /** Free text, as a picture's text would be — not something that could pass for a code or a model id. */
    private const string MARKER = 'MARKER 9c41f7 — tajný text z obrázku';

    public function testTheAdminSwitchesAiScanOnSetsTheLimitAndSwitchesItOff(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', '/edit-project/' . self::PROJECT);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#project-ai-scan h2', 'Funkce Premium');
        self::assertSelectorTextContains('#project-ai-scan .pd-st', 'Vypnuto');
        self::assertSelectorExists('#project-ai-scan button.sw[aria-checked="false"]');
        self::assertSame('10', $crawler->filter('#paiLimit')->attr('value'));

        // No <form> inside another <form>.
        self::assertCount(0, $crawler->filter('form form'));

        $client->submit($crawler->filter('#project-ai-scan .pd-mod-f')->form());
        self::assertResponseRedirects('/edit-project/' . self::PROJECT . '#project-ai-scan');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('#project-ai-scan .pd-st', 'Zapnuto');
        self::assertStringContainsString('AI sken zapnut pro projekt', $crawler->text());

        $row = self::connection()->fetchAssociative('SELECT ai_scan_enabled, ai_scan_monthly_limit, ai_scan_enabled_at, ai_scan_enabled_by_id FROM project WHERE id = :id', ['id' => self::PROJECT]);
        self::assertIsArray($row);
        self::assertTrue($row['ai_scan_enabled']);
        self::assertSame(10, $row['ai_scan_monthly_limit']);
        self::assertNotNull($row['ai_scan_enabled_at']);
        self::assertSame(TestDataFixture::ADMIN_USER_ID, $row['ai_scan_enabled_by_id']);

        // The limit: a number, then empty = no limit. The switch stays on.
        $client->submit($crawler->filter('#project-ai-scan form.pai-limit')->form(['monthly_limit' => '25']));
        $crawler = $client->followRedirect();
        self::assertSame('25', $crawler->filter('#paiLimit')->attr('value'));
        self::assertStringContainsString('Tento měsíc 0 z 25', $crawler->filter('#project-ai-scan')->text());

        $client->submit($crawler->filter('#project-ai-scan form.pai-limit')->form(['monthly_limit' => '']));
        $crawler = $client->followRedirect();
        self::assertNull(self::connection()->fetchOne('SELECT ai_scan_monthly_limit FROM project WHERE id = :id', ['id' => self::PROJECT]));
        self::assertTrue(self::connection()->fetchOne('SELECT ai_scan_enabled FROM project WHERE id = :id', ['id' => self::PROJECT]));

        $client->submit($crawler->filter('#project-ai-scan .pd-mod-f')->form());
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('#project-ai-scan .pd-st', 'Vypnuto');
        self::assertStringContainsString('AI sken vypnut', $crawler->text());
        self::assertNull(self::connection()->fetchOne('SELECT ai_scan_enabled_at FROM project WHERE id = :id', ['id' => self::PROJECT]));
    }

    public function testTheSwitchIsInvisibleToTheOwnerAndNeedsItsCsrfToken(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/edit-project/' . self::PROJECT);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#project-ai-scan');

        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        $client->request('POST', sprintf('/project/%s/ai-scan/entitlement', self::PROJECT), ['_token' => 'nope', 'enabled' => '1']);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse(self::connection()->fetchOne('SELECT ai_scan_enabled FROM project WHERE id = :id', ['id' => self::PROJECT]));
    }

    public function testTheStatusCommandShowsTheStateAndChangesTheModeForTheNextRequest(): void
    {
        $client = self::createClient();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $status = new CommandTester((new Application($kernel))->find('app:ai-scan:status'));

        $status->execute([]);
        $status->assertCommandIsSuccessful();
        self::assertStringContainsString('off · admins only', $status->getDisplay());
        self::assertStringContainsString('never seen', $status->getDisplay());

        // Off: an admin's upload is refused.
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        self::assertSame(503, $this->upload($client));

        // Switched on from the console — no deploy, no cache: the NEXT request starts a scan.
        $status->execute(['--mode' => 'on', '--admin-only' => '0', '--max-running' => '2', '--daily-ceiling-usd' => '12.5']);
        self::assertStringContainsString('on · admins + editors of entitled projects', $status->getDisplay());
        self::assertStringContainsString('of $12.50', $status->getDisplay());
        self::assertStringContainsString('max running 2', $status->getDisplay());
        self::getContainer()->get(\WBoost\Web\Services\AiScan\AiScanRuntimeState::class)->scannerSeen('test-1');

        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        self::assertSame(201, $this->upload($client));

        // Paused: the next upload is refused again, the scan in progress stays.
        $status->execute(['--mode' => 'paused']);
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        self::assertSame(503, $this->upload($client));
        self::assertSame(1, self::rowCount('ai_scan', "status = 'checking'"));

        // Off: it is failed as interrupted, refunded.
        $status->execute(['--mode' => 'off']);
        self::assertSame(1, self::rowCount('ai_scan', "status = 'failed' AND error_code = 'interrupted'"));
        self::assertSame(1, self::rowCount('ai_credit_ledger', "reason = 'refund'"));

        $status->execute(['--mode' => 'sometimes']);
        self::assertSame(2, $status->getStatusCode());
    }

    public function testTheStatusCommandListsScansShowsOneWithItsSpendAndCancelsOne(): void
    {
        $client = self::createClient();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $status = new CommandTester((new Application($kernel))->find('app:ai-scan:status'));
        self::entitle(TestDataFixture::PROJECT_1_ID, true, null);
        self::switchOn();

        // One scan running with a heartbeat's figures, one waiting.
        $build = self::running($client);
        self::scanner($client, 'POST', $build['id'] . '/beat', ['run' => 1, 'seq' => 1, 'stage' => 'build', 'usage' => self::usage(3, 'claude-opus-5-5')], $build['lease']);
        $waiting = self::start()->toString();

        $status->execute(['--scans' => true]);
        $status->assertCommandIsSuccessful();
        $list = $status->getDisplay();
        self::assertStringContainsString('In progress (2)', $list);
        self::assertStringContainsString($build['id'], $list);
        self::assertStringContainsString($waiting, $list);
        self::assertStringContainsString('running', $list);
        self::assertStringContainsString('checking', $list);

        // One scan: its usage rows are its spend — the open builder row carries the last heartbeat.
        $status->execute(['--scan' => $build['id']]);
        $status->assertCommandIsSuccessful();
        $detail = $status->getDisplay();
        self::assertStringContainsString('builder', $detail);
        self::assertStringContainsString('pre_check', $detail);
        self::assertStringContainsString('OPEN', $detail);
        self::assertStringContainsString('claude-opus-5-5', $detail);
        // 3000 in × $4 + 600 out × $20 + 1500 read × $0.2 + 50 written × $5, per million.
        self::assertStringContainsString('$0.024', $detail);
        self::assertStringContainsString('try 1: reserve -1', $detail);
        self::assertStringNotContainsString('Headline', $detail, 'no text of a design');

        // Cancelled from the console: the conditional transition of "Zrušit sken" — counted, because it was running.
        $status->execute(['--cancel' => $build['id']]);
        self::assertStringContainsString('Scan cancelled', $status->getDisplay());
        self::assertStringContainsString('cancelled', $status->getDisplay());
        self::assertSame('cancelled', self::text($build['id'], 'status'));
        self::assertSame(['1:reserve:-1', '1:settle:0'], self::ledger($build['id']));
        self::assertSame(['1:pre_check:passed', '1:builder:cancelled'], self::usageRows($build['id']));

        // A second cancel, an unknown id, something that is no id.
        $status->execute(['--cancel' => $build['id']]);
        self::assertStringContainsString('Nothing cancelled', $status->getDisplay());
        $status->execute(['--scan' => '00000000-0000-7000-8000-000000000000']);
        self::assertSame(1, $status->getStatusCode());
        $status->execute(['--cancel' => 'not-an-id']);
        self::assertSame(2, $status->getStatusCode());
    }

    public function testTheFakeRunDrivesAScanThroughEveryStateAndThePurgeCommandRuns(): void
    {
        self::createClient();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $fake = new CommandTester($application->find('app:ai-scan:fake-run'));

        // Mode off: it says what to do and changes nothing.
        $fake->execute(['project' => self::PROJECT, 'user' => TestDataFixture::ADMIN_USER_EMAIL]);
        self::assertSame(1, $fake->getStatusCode());
        self::assertStringContainsString('app:ai-scan:status --mode=on', $fake->getDisplay());
        self::assertSame(0, self::rowCount('ai_scan'));

        self::switchOn(scannerSeen: false);
        $fake->execute(['project' => self::PROJECT, 'user' => TestDataFixture::ADMIN_USER_EMAIL, '--scenario' => 'all']);
        $fake->assertCommandIsSuccessful($fake->getDisplay());

        /** @var array<string, int> $states */
        $states = self::connection()->fetchAllKeyValue('SELECT status, COUNT(*) FROM ai_scan GROUP BY status ORDER BY status');
        self::assertSame(['accepted' => 1, 'discarded' => 1, 'expired' => 1, 'ready' => 3, 'refused' => 1], $states);

        // Every state was passed through at least once.
        /** @var list<string> $visited */
        $visited = self::connection()->fetchFirstColumn("SELECT DISTINCT e->>'to' FROM ai_scan, jsonb_array_elements(events) e UNION SELECT 'checking'");
        self::assertEqualsCanonicalizing(
            ['checking', 'queued', 'running', 'ready', 'accepted', 'discarded', 'cancelled', 'stopped', 'failed', 'refused', 'expired'],
            $visited,
        );

        // Nothing is left open, and every reserve was closed exactly once.
        self::assertSame(0, self::rowCount('ai_usage_event', 'closed_at IS NULL'));
        self::assertSame(self::rowCount('ai_credit_ledger', "reason = 'reserve'"), self::rowCount('ai_credit_ledger', "reason IN ('settle', 'refund')"));

        $purge = new CommandTester($application->find('app:ai-scan:purge'));
        $purge->execute([]);
        $purge->assertCommandIsSuccessful();
        self::assertStringContainsString('Reaped 0', $purge->getDisplay());
    }

    /**
     * A complete scan — refusals and rejected designs included — with a marker
     * planted everywhere a picture's text could travel. Nothing at error level
     * (an error-level record is what becomes a Sentry event), and the marker
     * is in no log record at any level.
     */
    public function testACompleteScanLogsNoErrorAndNoneOfItsContent(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $handler = new TestHandler(Level::Debug);

        // Every channel but `doctrine`: its SQL log (with parameters) exists
        // only while `kernel.debug` is on — it is off in production.
        foreach (['monolog.logger', 'monolog.logger.request', 'monolog.logger.security', 'monolog.logger.messenger', 'monolog.logger.php', 'monolog.logger.console'] as $id) {
            $logger = self::getContainer()->has($id) ? self::getContainer()->get($id) : null;

            if ($logger instanceof Logger) {
                $logger->pushHandler($handler);
            }
        }

        self::entitle(self::PROJECT);
        self::switchOn();

        // Refusals first: wrong network, wrong token, wrong protocol, wrong lease, unknown scan.
        self::scanner($client, 'POST', 'tick', [], server: ['REMOTE_ADDR' => '127.0.0.1']);
        self::scanner($client, 'POST', 'tick', [], server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::MARKER]);
        self::scanner($client, 'POST', 'tick', [], server: ['HTTP_X_SCANNER_PROTOCOL' => '9']);
        self::scanner($client, 'POST', '0193a000-0000-7000-8000-000000000001/beat', ['run' => 1, 'note' => self::MARKER], self::MARKER);

        $id = self::start()->toString();
        $intake = self::claim($client, 'intake');
        self::scanner($client, 'PUT', $id . '/artifact/working?run=1', 'bytes ' . self::MARKER, $intake['lease']);
        self::scanner($client, 'PUT', $id . '/artifact/preview?run=1', 'bytes ' . self::MARKER, $intake['lease']);
        self::scanner($client, 'POST', $id . '/finish', ['run' => 1, 'outcome' => 'passed', 'preCheck' => ['verdict' => 'ok', 'text' => self::MARKER], 'usage' => self::usage(1)], $intake['lease']);

        $build = self::claim($client, 'build');
        self::scanner($client, 'POST', $id . '/beat', ['run' => 9, 'seq' => 1, 'usage' => []], $build['lease']);
        self::scanner($client, 'POST', $id . '/beat', ['run' => 1, 'seq' => 1, 'stage' => self::MARKER, 'note' => self::MARKER, 'noteParams' => ['n' => self::MARKER], 'usage' => ['model' => self::MARKER]], $build['lease']);

        // Rejected designs: a font that does not exist, and a broken document.
        self::scanner($client, 'POST', $id . '/render', ['run' => 1, 'seq' => 1, 'design' => self::design(self::MARKER, 'Missing ' . self::MARKER)], $build['lease']);
        self::scanner($client, 'POST', $id . '/render', ['run' => 1, 'seq' => 2, 'design' => ['canvas' => self::MARKER]], $build['lease']);
        self::scanner($client, 'POST', $id . '/render', ['run' => 1, 'seq' => 3, 'design' => self::design(self::MARKER)], $build['lease']);
        self::scanner($client, 'POST', $id . '/faces', ['run' => 1, 'seq' => 4, 'lines' => [self::MARKER]], $build['lease']);

        // An inconsistent report first is not possible twice on one run; send a valid one with a marked measurement.
        $done = self::scanner($client, 'POST', $id . '/finish', [
            'run' => 1, 'outcome' => 'proposed', 'candidate' => 1, 'report' => self::report(1),
            'boxes' => ['note' => 'a sentence with ' . self::MARKER], 'usage' => self::usage(2),
        ], $build['lease']);
        self::assertSame('ready', $done['json']['status'] ?? null, $done['raw']);

        // A second scan ends on an inconsistent report (the warning path).
        $second = self::running($client);
        $report = self::report(1);
        $report['status'] = self::MARKER . ' — a sentence';
        self::scanner($client, 'POST', $second['id'] . '/finish', ['run' => 1, 'outcome' => 'proposed', 'candidate' => 1, 'report' => $report, 'usage' => []], $second['lease']);
        self::assertSame('failed', self::text($second['id'], 'status'));

        // The user side: a refused upload and a wrong-state action.
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $client->request('POST', sprintf('/ai-scan/%s/retry', $id), ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(409);
        $client->request('GET', '/ai-scan/' . $id);
        self::assertResponseIsSuccessful();

        $errors = array_filter($handler->getRecords(), static fn ($record): bool => $record->level->value >= Level::Error->value);
        self::assertSame([], array_map(static fn ($record): string => $record->message, $errors), 'nothing at error level: no Sentry event');

        self::assertNotSame([], $handler->getRecords(), 'the handler did capture the run');

        foreach ($handler->getRecords() as $record) {
            self::assertStringNotContainsString(self::MARKER, $record->message . json_encode($record->context) . json_encode($record->extra));
        }

        // The marker did not reach the status document or the stored codes either.
        self::assertStringNotContainsString(self::MARKER, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString(self::MARKER, self::text($id, 'pre_check') . self::text($id, 'note') . self::text($id, 'stage') . self::text($id, 'note_params') . self::text($id, 'model'));
        self::assertStringNotContainsString(self::MARKER, (string) json_encode(self::dig(self::decoded(self::text($id, 'proposal')), 'measured')));
    }

    public function testTheSentryScrubberDropsBodiesAndTokensOfScanRoutesOnly(): void
    {
        $scrubber = new AiScanSentryScrubber();
        $request = [
            'url' => 'https://wboost.cz/internal/scanner/0193a000/render',
            'data' => ['design' => self::MARKER],
            'query_string' => 'run=1',
            'cookies' => ['PHPSESSID' => 'x'],
            'headers' => ['Authorization' => ['Bearer secret'], 'X-Scan-Lease' => ['lease'], 'User-Agent' => ['scanner']],
        ];

        foreach (['https://wboost.cz/internal/scanner/0193a000/render', 'https://wboost.cz/project/1/ai-scan', 'https://wboost.cz/ai-scan/0193a000/retry', 'https://wboost.cz/ai-scans/mine'] as $url) {
            $event = Event::createEvent();
            $event->setRequest([...$request, 'url' => $url]);

            self::assertSame(['url' => $url, 'headers' => ['User-Agent' => ['scanner']]], $scrubber($event)->getRequest(), $url);
        }

        $other = Event::createEvent();
        $other->setRequest([...$request, 'url' => 'https://wboost.cz/project/1/templates']);
        self::assertSame([...$request, 'url' => 'https://wboost.cz/project/1/templates'], $scrubber($other)->getRequest());
    }

    private function upload(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): int
    {
        $path = self::picture();

        $client->request(
            'POST',
            sprintf('/project/%s/ai-scan', self::PROJECT),
            ['_token' => 'csrf-token', 'kind' => 'template', 'requestId' => bin2hex(random_bytes(6)), 'consent' => '1'],
            ['picture' => new UploadedFile($path, 'design.png', 'image/png', null, true)],
            ['HTTP_ORIGIN' => 'http://localhost'],
        );

        @unlink($path);

        return $client->getResponse()->getStatusCode();
    }
}
