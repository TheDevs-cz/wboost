<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\AiScan;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Message\AiScan\SetAiScanRuntime;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * The upload and every start refusal (architecture.md §5.4, §5.6): each "no"
 * is an answer with a code, stores nothing and reserves nothing.
 */
final class StartAiScanTest extends WebTestCase
{
    use AiScanTesting;

    private const string PROJECT = TestDataFixture::PROJECT_1_ID;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::entitle(self::PROJECT);
        self::switchOn();
    }

    public function testAnUploadBecomesACheckingScanWithAReserveAndAPrivateFile(): void
    {
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);

        $answer = $this->upload(['requestId' => 'req-1', 'options' => '{"format":"portrait","note":"<b>x</b>","deep":{"a":1}}']);

        self::assertResponseStatusCodeSame(201);
        self::assertIsArray($answer['scan']);
        $scan = $answer['scan'];
        self::assertSame('checking', $scan['status']);
        self::assertSame('template', $scan['kind']);
        self::assertSame(1, $scan['try']);
        self::assertSame(2, $scan['retriesLeft']);
        self::assertNull($scan['waiting']);
        self::assertSame(0, $scan['queuePosition']);
        self::assertSame([0, 0], $scan['estimatedStartSeconds']);

        $scanId = $scan['id'];
        self::assertIsString($scanId);
        self::assertSame('web', self::text($scanId, 'channel'));
        self::assertSame(TestDataFixture::USER_1_EMAIL, self::text($scanId, 'requester_email'));
        self::assertSame('{"format": "portrait"}', self::text($scanId, 'options'), 'only flat codes travel to the scanner');
        self::assertSame(32, strlen(self::text($scanId, 'storage_secret')));
        self::assertSame(64, strlen(self::text($scanId, 'source_sha256')));
        self::assertStringContainsString('"ext": "png"', self::text($scanId, 'source_meta'));
        self::assertStringContainsString('"width": 900', self::text($scanId, 'source_meta'));
        self::assertSame(['1:reserve:-1'], self::ledger($scanId));

        // The file is in the private store, under the secret.
        $key = sprintf('/tmp/wboost-test-private/ai-scan/%s/%s/%s/original.png', self::PROJECT, $scanId, self::text($scanId, 'storage_secret'));
        self::assertFileExists($key);
        self::assertFileDoesNotExist(str_replace('wboost-test-private', 'wboost-test-uploads', $key));

        // Consent was recorded on the user, with its version.
        self::assertNotNull(self::connection()->fetchOne('SELECT ai_scan_consent_version FROM "user" WHERE email = :e', ['e' => TestDataFixture::USER_1_EMAIL]));

        // The same requestId again: the first scan, no second row, no second reserve.
        $again = $this->upload(['requestId' => 'req-1']);
        self::assertResponseStatusCodeSame(201);
        self::assertIsArray($again['scan']);
        self::assertSame($scan['id'], $again['scan']['id']);
        self::assertSame(1, self::rowCount('ai_scan'));
        self::assertSame(1, self::rowCount('ai_credit_ledger'));
    }

    public function testConsentIsRequiredOnceAndThenRemembered(): void
    {
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);

        $refused = $this->upload(['consent' => '0']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('consent_required', $refused['code'] ?? null);

        $this->upload(['consent' => '1']);
        self::assertResponseStatusCodeSame(201);

        $this->upload(['consent' => '0']);
        self::assertResponseStatusCodeSame(201);
    }

    public function testPicturesTheHeaderChecksRefuse(): void
    {
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);

        $svg = sys_get_temp_dir() . '/ai-scan-test.svg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="900"><rect width="900" height="900"/></svg>');
        $text = sys_get_temp_dir() . '/ai-scan-test.txt';
        file_put_contents($text, str_repeat('not a picture ', 100));

        foreach ([
            'type' => $svg,
            'type ' => $text,
            'small' => self::picture(599, 900),
            'side' => self::pngHeader(8001, 700),
            'pixels' => self::pngHeader(7000, 7000),
        ] as $reason => $path) {
            $answer = $this->upload([], $path);

            self::assertResponseStatusCodeSame(422, $reason);
            self::assertSame('unsupported_image', $answer['code'] ?? null);
            self::assertSame(trim($reason), $answer['reason'] ?? null);
            @unlink($path);
        }

        // Exactly at the limits is fine: 8000 px and 600 px sides.
        $this->upload([], self::pngHeader(8000, 600));
        self::assertResponseStatusCodeSame(201);

        // A calendar page may be smaller (short side ≥ 300 px): a real 977 × 457 px page passes,
        // the same file as a template scan does not, and 299 px is refused for a calendar too.
        $calendar = ['kind' => 'calendar', 'calendarId' => TestDataFixture::CALENDAR_1_ID];
        $answer = $this->upload([], self::pngHeader(977, 457));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('small', $answer['reason'] ?? null);
        $answer = $this->upload($calendar, self::pngHeader(640, 299));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('small', $answer['reason'] ?? null);
        $this->upload($calendar, self::pngHeader(977, 457));
        self::assertResponseStatusCodeSame(201);

        // No file at all.
        $this->client->request('POST', sprintf('/project/%s/ai-scan', self::PROJECT), ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(2, self::rowCount('ai_scan'));
    }

    public function testAFileOverTenMegabytesIsRefused(): void
    {
        $path = self::picture();
        // Padding after the PNG's end: still a PNG by its header, 10 000 001 bytes.
        file_put_contents($path, str_repeat("\0", 10_000_001 - (int) filesize($path)), FILE_APPEND);

        try {
            self::start(picture: $path);
            self::fail('expected a refusal');
        } catch (AiScanRefused $refused) {
            self::assertSame('unsupported_image', $refused->refusal->value);
            self::assertSame(['reason' => 'bytes'], $refused->params);
        } finally {
            @unlink($path);
        }
    }

    public function testAProjectWithoutFontsCannotStartATemplateScan(): void
    {
        self::entitle(TestDataFixture::PROJECT_2_ID);
        $this->assertRefused('no_fonts', fn () => self::start(TestDataFixture::PROJECT_2_ID, TestDataFixture::USER_2_EMAIL));
    }

    public function testACalendarScanNeedsACalendarOfTheProject(): void
    {
        $this->assertRefused('wrong_state', fn () => self::start(kind: AiScanKind::Calendar));
        // Another project's calendar, a calendar that does not exist.
        $this->assertRefused('wrong_state', fn () => self::start(kind: AiScanKind::Calendar, calendarId: TestDataFixture::CALENDAR_3_ID));
        $this->assertRefused('wrong_state', fn () => self::start(kind: AiScanKind::Calendar, calendarId: '00000000-0000-0000-0000-00000000dead'));

        $scanId = self::start(kind: AiScanKind::Calendar, calendarId: TestDataFixture::CALENDAR_1_ID)->toString();
        self::assertSame(TestDataFixture::CALENDAR_1_ID, self::row($scanId)['calendar_id']);
    }

    public function testACalendarScanNeedsNoProjectFontAndNoFourteenDayCalendarCanBeScanned(): void
    {
        // PROJECT_2 has no font: a template scan is refused there, a calendar scan is not.
        self::entitle(TestDataFixture::PROJECT_2_ID);
        $scanId = self::start(TestDataFixture::PROJECT_2_ID, TestDataFixture::USER_2_EMAIL, kind: AiScanKind::Calendar, calendarId: TestDataFixture::CALENDAR_3_ID)->toString();
        self::assertSame('checking', self::row($scanId)['status']);

        self::connection()->executeStatement("UPDATE calendar SET type = 'fortnight', layout = NULL, layout_preset = NULL WHERE id = :id", ['id' => TestDataFixture::CALENDAR_1_ID]);
        $this->assertRefused('wrong_state', fn () => self::start(kind: AiScanKind::Calendar, calendarId: TestDataFixture::CALENDAR_1_ID));
    }

    public function testTheMonthlyLimitCountsDeliveredScansAndRefundsGiveItBack(): void
    {
        $container = $this->client->getContainer();
        self::entitle(self::PROJECT, true, 2);

        $first = self::start()->toString();
        self::start();
        $this->assertRefused('limit_reached', fn () => self::start(), ['limit' => 2]);

        // A stopped scan is refunded: the month has room again.
        $intake = self::claim($this->client, 'intake');
        self::assertSame($first, $intake['id']);
        self::scanner($this->client, 'POST', $first . '/finish', ['run' => 1, 'outcome' => 'stopped', 'reason' => 'blurry_or_small', 'usage' => []], $intake['lease']);

        self::start();
        $this->assertRefused('limit_reached', fn () => self::start(), ['limit' => 2]);

        // No limit at all.
        self::entitle(self::PROJECT, true, null);
        self::start();
    }

    public function testDailyStartsCountEveryStartAndRefundsDoNotGiveOneBack(): void
    {
        $container = $this->client->getContainer();
        $this->bus()->dispatch(new SetAiScanRuntime(dailyStartsUser: 2));

        $first = self::start()->toString();
        // Refunded — the start stays counted.
        $intake = self::claim($this->client, 'intake');
        self::scanner($this->client, 'POST', $first . '/finish', ['run' => 1, 'outcome' => 'stopped', 'reason' => 'blurry_or_small', 'usage' => []], $intake['lease']);

        self::start();
        $this->assertRefused('daily_starts_reached', fn () => self::start());

        // Another user of the same project still may — until the project's own bound.
        self::start(email: TestDataFixture::ADMIN_USER_EMAIL);
        $this->bus()->dispatch(new SetAiScanRuntime(dailyStartsProject: 3));
        $this->assertRefused('daily_starts_reached', fn () => self::start(email: TestDataFixture::ADMIN_USER_EMAIL));
    }

    public function testAProjectMayHaveFourScansInProgressAndTenWaitInTotal(): void
    {
        $container = $this->client->getContainer();
        $this->bus()->dispatch(new SetAiScanRuntime(dailyStartsUser: 100, dailyStartsProject: 100));
        self::entitle(self::PROJECT, true, null);

        for ($i = 0; $i < 4; $i++) {
            self::start();
        }

        $this->assertRefused('project_queue_full', fn () => self::start());

        // Eight waiting in total — `checking` and `queued` together, here in
        // other projects: the next upload is refused before anything is stored.
        self::connection()->executeStatement("UPDATE ai_scan SET status = 'cancelled'");
        self::connection()->executeStatement(
            "INSERT INTO ai_scan (id, request_id, project_id, kind, requester_email, channel, status, storage_secret, source_sha256, source_meta, options, created_at, started_at, waiting_since)
             SELECT gen_random_uuid(), 'q' || n, :other, 'template', 'x@test.cz', 'web', CASE WHEN n <= 4 THEN 'queued' ELSE 'checking' END, 's', 'h', '{}', '{}', NOW(), NOW(), NOW() FROM generate_series(1, 8) n",
            ['other' => TestDataFixture::PROJECT_2_ID],
        );

        $this->assertRefused('queue_full', fn () => self::start());

        // A running scan is not waiting: one of the eight starts, and there is room for one more.
        self::connection()->executeStatement("UPDATE ai_scan SET status = 'running' WHERE id = (SELECT id FROM ai_scan WHERE status = 'queued' LIMIT 1)");
        self::start();
        $this->assertRefused('queue_full', fn () => self::start());
    }

    public function testUploadsAreRefusedWhileTheQueueCannotMove(): void
    {
        $container = $this->client->getContainer();

        // Scanner absent: nothing was heard for more than a minute.
        self::connection()->executeStatement("UPDATE ai_scan_runtime SET scanner_seen_at = NOW() - INTERVAL '70 seconds'");
        $this->assertRefused('temporarily_unavailable', fn () => self::start());

        self::connection()->executeStatement('UPDATE ai_scan_runtime SET scanner_seen_at = NULL');
        $this->assertRefused('temporarily_unavailable', fn () => self::start());

        self::switchOn();
        self::start();

        // Provider blocked.
        self::scanner($this->client, 'POST', 'tick', ['provider' => ['state' => 'blocked', 'error' => 'billing_error']]);
        $this->assertRefused('temporarily_unavailable', fn () => self::start());
        self::scanner($this->client, 'POST', 'tick', ['provider' => ['state' => 'ok']]);

        // A spend ceiling reached — the total one, then the project's.
        $this->bus()->dispatch(new SetAiScanRuntime(dailyCeilingMicroUsd: 0));
        $this->assertRefused('temporarily_unavailable', fn () => self::start());
        $this->bus()->dispatch(new SetAiScanRuntime(dailyCeilingMicroUsd: 20_000_000, projectDailyMicroUsd: 0));
        $this->assertRefused('temporarily_unavailable', fn () => self::start());
        $this->bus()->dispatch(new SetAiScanRuntime(projectDailyMicroUsd: 5_000_000));

        self::start();
    }

    public function testAPictureThatFailedTwiceIsRefusedAlsoWhenUploadedAgain(): void
    {
        $container = $this->client->getContainer();
        $picture = self::picture(seed: 0x336699);

        $scanId = self::start(picture: $picture)->toString();
        self::passIntake($this->client, $scanId);

        foreach ([1, 2] as $run) {
            $build = self::claim($this->client, 'build');
            self::scanner($this->client, 'POST', $scanId . '/finish', ['run' => $run, 'outcome' => 'failed', 'error' => 'timeout', 'usage' => []], $build['lease']);

            if ($run === 1) {
                TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);
                $this->client->request('POST', sprintf('/ai-scan/%s/retry', $scanId), ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);
                self::assertResponseIsSuccessful();
            }
        }

        self::assertSame(2, self::row($scanId)['failure_count']);

        // A further retry — although one of the two is still left by count…
        $this->client->request('POST', sprintf('/ai-scan/%s/retry', $scanId), ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('source_unscannable', self::dig(self::decoded($this->client->getResponse()->getContent()), 'code'));

        // …and the same file uploaded again.
        $this->assertRefused('source_unscannable', fn () => self::start(picture: $picture));

        // Another project is not affected; neither is another picture.
        self::start();
        @unlink($picture);
    }

    public function testRetryIsBoundedToTwoAndContinueToOnce(): void
    {
        $container = $this->client->getContainer();
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);

        // A stop → "Zkusit přesto" once.
        $scanId = self::start()->toString();
        $intake = self::claim($this->client, 'intake');
        self::scanner($this->client, 'PUT', $scanId . '/artifact/working?run=1', 'W', $intake['lease']);
        self::scanner($this->client, 'POST', $scanId . '/finish', ['run' => 1, 'outcome' => 'stopped', 'reason' => 'photo_of_print', 'usage' => []], $intake['lease']);

        $status = $this->action($scanId, 'retry');
        self::assertSame('wrong_state', $status['code'] ?? null, 'a stopped scan is continued, not retried');

        $continued = $this->action($scanId, 'continue');
        self::assertIsArray($continued['scan']);
        self::assertSame('queued', $continued['scan']['status']);
        self::assertTrue($continued['scan']['approximate']);
        self::assertSame(2, $continued['scan']['try']);
        self::assertSame(2, $continued['scan']['retriesLeft'], 'continuing is not one of the two retries');
        self::assertSame(['1:reserve:-1', '1:refund:1', '2:reserve:-1'], self::ledger($scanId));

        // The pre-check is not repeated: it goes straight to a builder.
        $build = self::claim($this->client, 'build');
        self::assertSame($scanId, $build['id']);
        self::assertTrue($build['job']['approximate']);
        self::scanner($this->client, 'POST', $scanId . '/finish', ['run' => 1, 'outcome' => 'failed', 'error' => 'provider_unavailable', 'usage' => []], $build['lease']);

        self::assertSame('wrong_state', $this->action($scanId, 'continue')['code'] ?? null);

        // provider_unavailable twice more would make the picture unscannable;
        // keep this test about the COUNT of retries: clear the failure tally.
        foreach ([2, 3] as $run) {
            self::connection()->executeStatement('UPDATE ai_scan SET failure_count = 0');
            $retried = $this->action($scanId, 'retry');
            self::assertIsArray($retried['scan'], json_encode($retried) ?: '');
            self::assertSame(3 - $run, $retried['scan']['retriesLeft']);

            $build = self::claim($this->client, 'build');
            self::assertSame($run, $build['run']);
            // (Alternating causes: three failures of ONE class in a row would trip the circuit breaker.)
            self::scanner($this->client, 'POST', $scanId . '/finish', ['run' => $run, 'outcome' => 'failed', 'error' => $run === 2 ? 'renderer_unavailable' : 'provider_unavailable', 'usage' => []], $build['lease']);
        }

        self::connection()->executeStatement('UPDATE ai_scan SET failure_count = 0');
        $third = $this->action($scanId, 'retry');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_retries_left', $third['code'] ?? null);

        $row = self::row($scanId);
        self::assertSame(4, $row['try']);
        self::assertSame(
            ['1:reserve:-1', '1:refund:1', '2:reserve:-1', '2:refund:1', '3:reserve:-1', '3:refund:1', '4:reserve:-1', '4:refund:1'],
            self::ledger($scanId),
        );
    }

    public function testThreeProviderRefusalsADayPauseTheProject(): void
    {
        $container = $this->client->getContainer();

        foreach ([1, 2, 3] as $n) {
            $build = self::running($this->client);
            self::scanner($this->client, 'POST', $build['id'] . '/finish', ['run' => 1, 'outcome' => 'refused', 'usage' => []], $build['lease']);
        }

        $this->assertRefused('project_paused', fn () => self::start());

        // Yesterday's refusals do not count.
        self::connection()->executeStatement("UPDATE ai_usage_event SET closed_at = closed_at - INTERVAL '2 days'");
        self::start();
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function upload(array $fields = [], null|string $path = null): array
    {
        $own = $path === null;
        $path ??= self::picture();

        $this->client->request(
            'POST',
            sprintf('/project/%s/ai-scan', self::PROJECT),
            ['_token' => 'csrf-token', 'kind' => 'template', 'requestId' => bin2hex(random_bytes(6)), 'consent' => '1', ...$fields],
            ['picture' => new UploadedFile($path, basename($path), null, null, true)],
            ['HTTP_ORIGIN' => 'http://localhost'],
        );

        if ($own) {
            @unlink($path);
        }

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        /** @var array<string, mixed> $json */
        $json = is_array($decoded) ? $decoded : [];

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    private function action(string $scanId, string $action): array
    {
        $this->client->request('POST', sprintf('/ai-scan/%s/%s', $scanId, $action), ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        /** @var array<string, mixed> $json */
        $json = is_array($decoded) ? $decoded : [];

        return $json;
    }

    /**
     * @param callable(): mixed $start
     * @param null|array<string, int|string> $params
     */
    private function assertRefused(string $code, callable $start, null|array $params = null): void
    {
        $before = self::rowCount('ai_credit_ledger');

        try {
            $start();
            self::fail('expected the refusal ' . $code);
        } catch (AiScanRefused $refused) {
            self::assertSame($code, $refused->refusal->value);

            if ($params !== null) {
                self::assertSame($params, $refused->params);
            }
        }

        self::assertSame($before, self::rowCount('ai_credit_ledger'), 'a refusal reserves nothing');
    }

    /**
     * A file that IS a PNG as far as its header goes, declaring any size —
     * without the megabytes of pixels (the web process reads headers only).
     */
    private static function pngHeader(int $width, int $height): string
    {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $path = sprintf('%s/ai-scan-header-%s.png', sys_get_temp_dir(), bin2hex(random_bytes(6)));

        file_put_contents($path, "\x89PNG\r\n\x1A\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr)) . random_bytes(64));

        return $path;
    }
}
