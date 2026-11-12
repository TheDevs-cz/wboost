<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Internal;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Message\AiScan\CancelAiScan;
use WBoost\Web\Message\AiScan\RetryAiScan;
use WBoost\Web\Message\AiScan\SetAiScanRuntime;
use WBoost\Web\Services\AiScan\AiScanRuntimeState;
use WBoost\Web\Services\AiScan\AiScanStandIn;
use WBoost\Web\Services\AiScan\Template\ScanRenderLimiter;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\AiScan\AiScanMode;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The scanner API (`/internal/scanner/*`), driven the way the scanner drives
 * it: from the scan subnet, with the token, the protocol header and — after
 * `claim` — the lease token and the run.
 */
final class ScannerApiTest extends WebTestCase
{
    use AiScanTesting;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::entitle(TestDataFixture::PROJECT_1_ID, true, null);
        self::switchOn();
    }

    // ---- who may call ------------------------------------------------------

    public function testACallFromOutsideTheScanSubnetGets404EvenWithAValidToken(): void
    {
        foreach (['127.0.0.1', '10.98.0.5', '10.99.1.5', '172.18.0.4', '8.8.8.8'] as $peer) {
            $answer = self::scanner($this->client, 'POST', 'tick', [], server: ['REMOTE_ADDR' => $peer]);

            self::assertSame(404, $answer['status'], $peer);
            self::assertSame('', $answer['raw'], 'a refusal says nothing');
        }

        // …while the same request from inside the subnet is served.
        self::assertSame(200, self::scanner($this->client, 'POST', 'tick', [])['status']);
    }

    public function testARequestThatCameThroughAProxyGets404(): void
    {
        // The public router adds X-Forwarded-For; the scanner calls directly.
        // (And `getClientIp()` would have believed this header.)
        $answer = self::scanner($this->client, 'POST', 'tick', [], server: ['HTTP_X_FORWARDED_FOR' => self::SCAN_IP]);
        self::assertSame(404, $answer['status']);

        $spoofed = self::scanner($this->client, 'POST', 'tick', [], server: ['REMOTE_ADDR' => '172.18.0.4', 'HTTP_X_FORWARDED_FOR' => self::SCAN_IP]);
        self::assertSame(404, $spoofed['status']);
    }

    public function testAWrongOrMissingTokenGets404AndThePreviousTokenIsStillAccepted(): void
    {
        foreach (['Bearer nope', 'Bearer ', 'Basic ' . self::TOKEN, self::TOKEN, 'Bearer ' . self::TOKEN . 'x'] as $header) {
            self::assertSame(404, self::scanner($this->client, 'POST', 'tick', [], server: ['HTTP_AUTHORIZATION' => $header])['status'], $header);
        }

        self::assertSame(404, self::scanner($this->client, 'POST', 'tick', [], server: ['HTTP_AUTHORIZATION' => ''])['status']);
        self::assertSame(200, self::scanner($this->client, 'POST', 'tick', [], server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::PREVIOUS_TOKEN])['status']);
    }

    public function testEveryEndpointIs404WithoutTheScannersCredentials(): void
    {
        $id = '0193a000-0000-7000-8000-000000000001';

        foreach ([
            ['POST', 'claim'], ['POST', 'tick'], ['GET', $id . '/source'], ['PUT', $id . '/artifact/working'],
            ['POST', $id . '/beat'], ['POST', $id . '/render'], ['POST', $id . '/faces'], ['POST', $id . '/finish'], ['POST', $id . '/release'],
        ] as [$method, $path]) {
            self::assertSame(404, self::scanner($this->client, $method, $path, [], server: ['REMOTE_ADDR' => '127.0.0.1'])['status'], $path);
        }

        // A logged-in admin is not the scanner either.
        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);
        $this->client->request('POST', '/internal/scanner/tick');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheProtocolVersionIsCheckedOnEveryCall(): void
    {
        foreach (['0', '2', '', 'abc'] as $version) {
            $answer = self::scanner($this->client, 'POST', 'tick', ['scannerVersion' => 'old-0'], server: ['HTTP_X_SCANNER_PROTOCOL' => $version]);

            self::assertSame(200, $answer['status']);
            self::assertSame(['ok' => false, 'code' => 'protocol_too_old', 'protocolVersion' => 1], $answer['json']);
        }

        $runtime = $this->runtime();
        self::assertTrue($runtime->scannerTooOld);

        // Uploads are refused while the only scanner is too old…
        try {
            self::start();
            self::fail('expected a refusal');
        } catch (\WBoost\Web\Value\AiScan\AiScanRefused $refused) {
            self::assertSame('temporarily_unavailable', $refused->refusal->value);
        }

        // …and a scanner speaking the app's version clears the flag.
        self::assertTrue(self::scanner($this->client, 'POST', 'tick', ['scannerVersion' => 'new-1'])['json']['ok'] ?? false);
        self::assertFalse($this->runtime()->scannerTooOld);
        self::assertSame('new-1', $this->runtime()->scannerVersion);
    }

    // ---- claim -------------------------------------------------------------

    public function testClaimSaysWhyThereIsNoJob(): void
    {
        $empty = self::scanner($this->client, 'POST', 'claim', ['lane' => 'intake', 'scannerVersion' => 'test-1', 'instruments' => self::instruments(), 'capacity' => 1]);
        self::assertSame(['ok' => true, 'mode' => 'on', 'job' => null, 'reason' => null], $empty['json']);

        self::start();

        $this->bus()->dispatch(new SetAiScanRuntime(mode: AiScanMode::Paused));
        self::assertSame(['ok' => true, 'mode' => 'paused', 'job' => null, 'reason' => 'paused'], $this->claimRaw('intake'));

        $this->bus()->dispatch(new SetAiScanRuntime(mode: AiScanMode::On));
        self::scanner($this->client, 'POST', 'tick', ['provider' => ['state' => 'blocked', 'error' => 'billing_error']]);
        self::assertSame(['ok' => true, 'mode' => 'on', 'job' => null, 'reason' => 'provider_blocked'], $this->claimRaw('intake'));
        self::assertSame('billing_error', $this->runtime()->providerError);

        // The probe succeeds: the state clears by itself.
        self::scanner($this->client, 'POST', 'tick', ['provider' => ['state' => 'ok']]);
        self::assertIsArray($this->claimRaw('intake')['job']);

        self::assertSame('invalid_request', self::scanner($this->client, 'POST', 'claim', ['lane' => 'other'])['json']['code'] ?? null);

        // A scanner without an instrument the definition requires is too old: no job, and uploads stop.
        if (self::instruments() !== []) {
            $old = self::scanner($this->client, 'POST', 'claim', ['lane' => 'intake', 'scannerVersion' => 'old-0', 'instruments' => array_slice(self::instruments(), 1), 'capacity' => 1]);
            self::assertSame('scanner_too_old', $old['json']['reason'] ?? null);
            self::assertTrue($this->runtime()->scannerTooOld);
        }
    }

    public function testABuildClaimHonoursMaxRunningAndTheSpendCeilings(): void
    {
        $container = $this->client->getContainer();
        $first = self::start()->toString();
        $second = self::start()->toString();
        self::passIntake($this->client, $first);
        self::passIntake($this->client, $second);

        $build = self::claim($this->client, 'build');
        self::assertSame($first, $build['id'], 'oldest first');

        // max_running = 1: the second stays queued while the first runs.
        self::assertNull($this->claimRaw('build')['job']);

        self::scanner($this->client, 'POST', $first . '/release', ['run' => $build['run'], 'usage' => []], $build['lease']);

        // Project ceiling below one run's reserved budget ($1.50): nothing of that project starts.
        $this->bus()->dispatch(new SetAiScanRuntime(projectDailyMicroUsd: 1_000_000));
        self::assertNull($this->claimRaw('build')['job']);
        self::assertNull($this->claimRaw('build')['reason']);

        // Total ceiling reached: the claim says so.
        $this->bus()->dispatch(new SetAiScanRuntime(dailyCeilingMicroUsd: 0, projectDailyMicroUsd: 5_000_000));
        self::assertSame('spend_ceiling', $this->claimRaw('build')['reason']);

        $this->bus()->dispatch(new SetAiScanRuntime(dailyCeilingMicroUsd: 20_000_000));
        self::assertSame($first, self::claim($this->client, 'build')['id']);
    }

    // ---- one whole scan ----------------------------------------------------

    public function testAWholeTemplateScanFromUploadToProposal(): void
    {
        $container = $this->client->getContainer();
        $picture = self::picture();
        $original = (string) file_get_contents($picture);
        $scanId = self::start(picture: $picture)->toString();
        @unlink($picture);

        self::assertSame('checking', self::row($scanId)['status']);
        self::assertSame(['1:reserve:-1'], self::ledger($scanId));

        // -- intake
        $intake = self::claim($this->client, 'intake');
        self::assertSame($scanId, $intake['id']);
        self::assertSame(1, $intake['run']);
        self::assertSame('intake', $intake['job']['lane']);
        self::assertSame('template', $intake['job']['kind']);
        self::assertSame(150, $intake['job']['leaseSeconds']);
        self::assertArrayNotHasKey('context', $intake['job']);
        self::assertSame(64, strlen($intake['lease']));
        self::assertSame('image/png', self::dig($intake['job'], 'source', 'contentType'));
        self::assertSame(900, self::dig($intake['job'], 'source', 'width'));
        self::assertIsString(self::dig($intake['job'], 'definition', 'files', 'definition.json'));
        self::assertIsString(self::dig($intake['job'], 'definition', 'files', 'finish.template.schema.json'));
        self::assertIsString(self::dig($intake['job'], 'definition', 'version'));

        $source = self::scanner($this->client, 'GET', $scanId . '/source?run=1', null, $intake['lease']);
        self::assertSame(200, $source['status']);
        self::assertSame($original, $source['raw'], 'the original, byte for byte');
        self::assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));

        // No working copy yet: the intake cannot pass.
        $early = self::scanner($this->client, 'POST', $scanId . '/finish', ['run' => 1, 'outcome' => 'passed', 'usage' => []], $intake['lease']);
        self::assertSame('missing_artifact', $early['json']['code'] ?? null);

        self::assertSame(['ok' => true, 'bytes' => 12], self::scanner($this->client, 'PUT', $scanId . '/artifact/working?run=1', 'WORKING-COPY', $intake['lease'])['json']);
        self::assertTrue(self::scanner($this->client, 'PUT', $scanId . '/artifact/preview?run=1', 'PREVIEW', $intake['lease'])['json']['ok'] ?? false);

        $beat = self::scanner($this->client, 'POST', $scanId . '/beat', ['run' => 1, 'seq' => 1, 'stage' => 'check', 'usage' => self::usage(1, 'haiku')], $intake['lease']);
        self::assertFalse($beat['json']['cancel'] ?? null);
        self::assertSame('on', $beat['json']['mode'] ?? null);

        $passed = self::scanner($this->client, 'POST', $scanId . '/finish', [
            'run' => 1, 'outcome' => 'passed',
            'preCheck' => ['verdict' => 'ok', 'confidence' => 0.93, 'note' => 'A sentence the model wrote must not be stored.'],
            'sourceMeta' => ['workingWidth' => 900, 'workingHeight' => 1200],
            'usage' => self::usage(1, 'haiku'),
        ], $intake['lease']);
        self::assertSame(['ok' => true, 'applied' => true, 'status' => 'queued', 'code' => null], $passed['json']);

        self::assertSame('{"verdict": "ok", "confidence": 0.93}', self::text($scanId, 'pre_check'));
        self::assertStringContainsString('"workingWidth": 900', self::text($scanId, 'source_meta'));
        self::assertSame('', self::text($scanId, 'lease_expires_at'));
        self::assertSame(['1:pre_check:passed'], self::usageRows($scanId));

        // Repeating the finish changes nothing: that run is over.
        $again = self::scanner($this->client, 'POST', $scanId . '/finish', ['run' => 1, 'outcome' => 'passed', 'usage' => self::usage(1, 'haiku')], $intake['lease']);
        self::assertSame(409, $again['status']);
        self::assertSame(['ok' => false, 'code' => 'stale_run', 'status' => 'queued'], $again['json']);

        // -- build
        $templates = $this->templateCount();
        $build = self::claim($this->client, 'build');
        self::assertSame($scanId, $build['id']);
        self::assertSame(1, $build['run']);
        self::assertNotSame($intake['lease'], $build['lease']);
        self::assertContains(self::FACE, array_column(self::decoded(self::dig($build['job'], 'context', 'fonts')), 'family'));
        self::assertSame(AiScanStandIn::ASSET_ID, self::dig($build['job'], 'context', 'standInAssetId'));
        self::assertSame('running', self::text($scanId, 'status'));

        $working = self::scanner($this->client, 'GET', $scanId . '/source?run=1', null, $build['lease']);
        self::assertSame('WORKING-COPY', $working['raw']);

        // The intake's token is of no use any more.
        self::assertSame(409, self::scanner($this->client, 'GET', $scanId . '/source?run=1', null, $intake['lease'])['status']);

        $beat = self::scanner($this->client, 'POST', $scanId . '/beat', [
            'run' => 1, 'seq' => 1, 'stage' => 'analyse', 'note' => 'texts_found', 'noteParams' => ['n' => 7, 'text' => 'free text is dropped'],
            'round' => 1, 'usage' => self::usage(2), 'resources' => ['rssMb' => 512, 'label' => 'dropped'],
        ], $build['lease']);
        self::assertFalse($beat['json']['cancel'] ?? null);

        self::assertSame('analyse', self::text($scanId, 'stage'));
        self::assertSame('texts_found', self::text($scanId, 'note'));
        self::assertSame('{"n": 7}', self::text($scanId, 'note_params'));
        self::assertSame(1, self::number($scanId, 'round'));
        self::assertSame('{"rssMb": 512}', self::text($scanId, 'resources'));
        // Totals are the sums of both steps' cumulative figures.
        self::assertSame(1000 + 2000, self::number($scanId, 'input_tokens'));
        self::assertSame(100_000 + 200_000, self::number($scanId, 'cost_micro_usd'));

        $render = self::scanner($this->client, 'POST', $scanId . '/render', ['run' => 1, 'seq' => 10, 'design' => self::design()], $build['lease']);
        self::assertTrue($render['json']['ok'] ?? false, $render['raw']);
        self::assertSame(1, $render['json']['candidate'] ?? null);
        self::assertSame(900, $render['json']['width'] ?? null);
        self::assertSame('image/webp', self::dig($render['json'], 'image', 'contentType'));
        $base64 = self::dig($render['json'], 'image', 'base64');
        self::assertIsString($base64);
        self::assertStringStartsWith('RIFF', (string) base64_decode($base64, true));

        // Render persisted no template and no variant — and scheduled none.
        self::assertSame($templates, $this->templateCount());

        // Idempotent by (run, seq): the same candidate, nothing rendered twice.
        $replay = self::scanner($this->client, 'POST', $scanId . '/render', ['run' => 1, 'seq' => 10, 'design' => self::design()], $build['lease']);
        self::assertSame(1, $replay['json']['candidate'] ?? null);
        self::assertSame($base64, self::dig($replay['json'], 'image', 'base64'));
        self::assertSame(1, self::number($scanId, 'renders'));
        self::assertSame(1, self::number($scanId, 'preview_seq'));

        $faces = self::scanner($this->client, 'POST', $scanId . '/faces', ['run' => 1, 'seq' => 11, 'lines' => ['Headline', 'Second'], 'faces' => [self::FACE], 'size' => 48], $build['lease']);
        self::assertTrue($faces['json']['ok'] ?? false);
        self::assertCount(2, self::decoded($faces['json']['cells'] ?? null));
        self::assertSame(1, self::number($scanId, 'renders'), 'faces is not a candidate');

        $finish = self::scanner($this->client, 'POST', $scanId . '/finish', [
            'run' => 1, 'outcome' => 'proposed', 'candidate' => 1, 'report' => self::report(1),
            'boxes' => ['elements' => ['headline' => ['x' => 80, 'y' => 120, 'w' => 740, 'h' => 90, 'colour' => '#111111', 'text' => 'A sentence is dropped']]],
            'usage' => self::usage(3),
        ], $build['lease']);
        self::assertSame(['ok' => true, 'applied' => true, 'status' => 'ready', 'code' => null], $finish['json']);

        self::assertSame('ready', self::text($scanId, 'status'));
        self::assertSame(1, self::number($scanId, 'kept_candidate'));
        self::assertSame('', self::text($scanId, 'unfinished'), 'false');
        self::assertNotSame('', self::text($scanId, 'finished_at'));
        self::assertNotSame('', self::text($scanId, 'expires_at'));

        $proposal = self::decoded(self::text($scanId, 'proposal'));
        self::assertSame(1, self::dig($proposal, 'candidate'));
        // JSONB keeps the order of LISTS (the elements), not of keys.
        self::assertEquals(self::design(), self::dig($proposal, 'document'));
        self::assertSame(['bg', 'headline'], array_column(self::decoded(self::dig($proposal, 'document', 'elements')), 'id'));
        self::assertEquals(['x' => 80, 'y' => 120, 'w' => 740, 'h' => 90, 'colour' => '#111111'], self::dig($proposal, 'measured', 'elements', 'headline'), 'the sentence was dropped');

        self::assertSame(['1:reserve:-1', '1:settle:0'], self::ledger($scanId));
        self::assertSame(['1:pre_check:passed', '1:builder:proposed'], self::usageRows($scanId));
        self::assertSame(1000 + 3000, self::number($scanId, 'input_tokens'), 'the builder row holds the LAST cumulative figure');

        // Idempotent per (scan, run): a repeat is answered, nothing moves, one settle.
        $repeat = self::scanner($this->client, 'POST', $scanId . '/finish', ['run' => 1, 'outcome' => 'proposed', 'candidate' => 1, 'report' => self::report(1), 'usage' => self::usage(3)], $build['lease']);
        self::assertSame(409, $repeat['status']);
        self::assertSame(['ok' => false, 'code' => 'stale_run', 'status' => 'ready'], $repeat['json']);
        self::assertSame(['1:reserve:-1', '1:settle:0'], self::ledger($scanId));

        // After the end the lease serves no picture.
        self::assertSame(409, self::scanner($this->client, 'GET', $scanId . '/source?run=1', null, $build['lease'])['status']);
    }

    // ---- lease, run, idempotency -------------------------------------------

    public function testAStaleRunAndAWrongLeaseAreRefusedWith409(): void
    {
        $build = self::running($this->client);
        $id = $build['id'];

        $stale = self::scanner($this->client, 'POST', $id . '/beat', ['run' => 2, 'seq' => 1, 'usage' => []], $build['lease']);
        self::assertSame(409, $stale['status']);
        self::assertSame(['ok' => false, 'code' => 'stale_run'], $stale['json']);

        foreach ([str_repeat('0', 64), '', null] as $lease) {
            $lost = self::scanner($this->client, 'POST', $id . '/beat', ['run' => 1, 'seq' => 1, 'usage' => self::usage(9)], $lease);
            self::assertSame(409, $lost['status']);
            self::assertSame(['ok' => false, 'code' => 'lease_lost'], $lost['json']);
        }

        // Nothing of those calls was recorded.
        self::assertSame(1000, self::row($id)['input_tokens'], 'only the intake\'s usage');

        self::assertSame(409, self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'seq' => 1, 'design' => self::design()], 'wrong')['status']);
        self::assertSame(409, self::scanner($this->client, 'POST', $id . '/finish', ['run' => 3, 'outcome' => 'refused'], $build['lease'])['status']);
        self::assertSame('running', self::row($id)['status']);
    }

    public function testAnUnknownScanIsGone(): void
    {
        $answer = self::scanner($this->client, 'POST', '0193a000-0000-7000-8000-000000000001/beat', ['run' => 1, 'seq' => 1], 'x');

        self::assertSame(410, $answer['status']);
        self::assertSame(['ok' => false, 'code' => 'gone'], $answer['json']);
    }

    public function testADeletedProjectAnswers410ToTheScanner(): void
    {
        $build = self::running($this->client, TestDataFixture::PROJECT_1_ID);
        $container = $this->client->getContainer();

        // What the cascade of a project deletion does to the scan row.
        self::connection()->executeStatement('DELETE FROM ai_scan WHERE id = :id', ['id' => $build['id']]);

        foreach (['beat', 'render', 'faces', 'finish', 'release'] as $endpoint) {
            $answer = self::scanner($this->client, 'POST', $build['id'] . '/' . $endpoint, ['run' => 1, 'seq' => 1], $build['lease']);
            self::assertSame(410, $answer['status'], $endpoint);
            self::assertSame('gone', $answer['json']['code'] ?? null);
        }

        // The usage rows are FK-less: they outlive the scan.
        self::assertNotSame([], self::usageRows($build['id']));
    }

    public function testBeatIsIdempotentBySeqAndUsageNeverGoesBack(): void
    {
        $build = self::running($this->client);
        $container = $this->client->getContainer();
        $id = $build['id'];

        self::scanner($this->client, 'POST', $id . '/beat', ['run' => 1, 'seq' => 5, 'stage' => 'build', 'usage' => self::usage(5)], $build['lease']);
        self::assertSame(5, self::row($id)['beat_seq']);
        self::assertSame('build', self::row($id)['stage']);

        // An older heartbeat arriving late: answered, but it changes neither the stage nor the figures.
        $late = self::scanner($this->client, 'POST', $id . '/beat', ['run' => 1, 'seq' => 3, 'stage' => 'read', 'usage' => self::usage(3)], $build['lease']);
        self::assertSame(200, $late['status']);
        self::assertFalse($late['json']['cancel'] ?? null);

        // The same heartbeat twice.
        self::scanner($this->client, 'POST', $id . '/beat', ['run' => 1, 'seq' => 5, 'stage' => 'refine', 'usage' => self::usage(5)], $build['lease']);

        $row = self::row($id);
        self::assertSame(5, $row['beat_seq']);
        self::assertSame('build', $row['stage']);
        self::assertSame(1000 + 5000, $row['input_tokens']);
        self::assertSame(['1:pre_check:passed', '1:builder:open'], self::usageRows($id));
    }

    public function testAHeartbeatExtendsTheLease(): void
    {
        $build = self::running($this->client);
        $container = $this->client->getContainer();

        self::connection()->executeStatement("UPDATE ai_scan SET lease_expires_at = NOW() + INTERVAL '5 seconds' WHERE id = :id", ['id' => $build['id']]);
        $before = self::row($build['id'])['lease_expires_at'];

        $beat = self::scanner($this->client, 'POST', $build['id'] . '/beat', ['run' => 1, 'seq' => 1, 'usage' => []], $build['lease']);

        self::assertIsString($beat['json']['leaseExpiresAt'] ?? null);
        self::assertGreaterThan($before, self::row($build['id'])['lease_expires_at']);
    }

    public function testALateFinishIsAcceptedWhileTheScanIsQueuedByTheReaperAndUnclaimed(): void
    {
        $build = self::running($this->client);
        $container = $this->client->getContainer();
        $id = $build['id'];

        self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'seq' => 1, 'design' => self::design()], $build['lease']);
        $this->expireLease($id);
        self::assertSame(1, self::scanner($this->client, 'POST', 'tick', [])['json']['reaped'] ?? null);

        $row = self::row($id);
        self::assertSame('queued', $row['status']);
        self::assertSame(1, $row['auto_restart']);
        self::assertSame(['1:pre_check:passed', '1:builder:lease_lost'], self::usageRows($id));

        // The run had not died after all — its finish arrives: accepted.
        $finish = self::scanner($this->client, 'POST', $id . '/finish', ['run' => 1, 'outcome' => 'proposed', 'candidate' => 1, 'report' => self::report(1), 'usage' => self::usage(4)], $build['lease']);
        self::assertSame(['ok' => true, 'applied' => true, 'status' => 'ready', 'code' => null], $finish['json']);
        self::assertSame(['1:reserve:-1', '1:settle:0'], self::ledger($id));
        // The usage row keeps its first outcome and takes the later figures.
        self::assertSame(1000 + 4000, self::row($id)['input_tokens']);
    }

    public function testALateFinishIsRefusedOnceTheScanWasClaimedAgain(): void
    {
        $build = self::running($this->client);
        $container = $this->client->getContainer();
        $id = $build['id'];

        $this->expireLease($id);
        self::scanner($this->client, 'POST', 'tick', []);

        $second = self::claim($this->client, 'build');
        self::assertSame($id, $second['id']);
        self::assertSame(2, $second['run']);

        // The first run's token names a run that is no longer the latest.
        $late = self::scanner($this->client, 'POST', $id . '/finish', ['run' => 1, 'outcome' => 'refused', 'usage' => self::usage(9)], $build['lease']);
        self::assertSame(409, $late['status']);
        self::assertSame('lease_lost', $late['json']['code'] ?? null);
        self::assertSame('running', self::row($id)['status']);

        // The second run loses its lease too: no second restart — failed, refunded.
        $this->expireLease($id);
        self::scanner($this->client, 'POST', 'tick', []);

        $row = self::row($id);
        self::assertSame('failed', $row['status']);
        self::assertSame('interrupted', $row['error_code']);
        self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($id));
        self::assertSame(['1:pre_check:passed', '1:builder:lease_lost', '2:builder:lease_lost'], self::usageRows($id));
        self::assertSame(1, $row['failure_count'], 'a run that died twice counts against the picture');
    }

    public function testReleasePutsTheScanBackWithoutConsumingTheRestart(): void
    {
        $build = self::running($this->client);
        $container = $this->client->getContainer();
        $id = $build['id'];

        $released = self::scanner($this->client, 'POST', $id . '/release', ['run' => 1, 'usage' => self::usage(2), 'reason' => 'shutdown'], $build['lease']);
        self::assertSame(['ok' => true, 'applied' => true, 'status' => 'queued', 'code' => null], $released['json']);

        $row = self::row($id);
        self::assertSame(0, $row['auto_restart']);
        self::assertNull($row['lease_expires_at']);
        self::assertSame(['1:reserve:-1'], self::ledger($id));
        self::assertSame(['1:pre_check:passed', '1:builder:released'], self::usageRows($id));

        // A released run cannot finish late: it gave the scan back.
        self::assertSame(409, self::scanner($this->client, 'POST', $id . '/finish', ['run' => 1, 'outcome' => 'refused', 'usage' => []], $build['lease'])['status']);

        // The next claim is run 2 and may still be restarted once.
        self::assertSame(2, self::claim($this->client, 'build')['run']);
    }

    public function testReleaseWithAProviderBlockStopsClaims(): void
    {
        $build = self::running($this->client);

        self::scanner($this->client, 'POST', $build['id'] . '/release', ['run' => 1, 'usage' => [], 'reason' => 'provider_blocked', 'providerError' => 'authentication_failed'], $build['lease']);

        self::assertSame('queued', self::row($build['id'])['status']);
        self::assertSame('provider_blocked', $this->claimRaw('build')['reason']);
    }

    public function testAnIntakeReleaseGivesTheTryBack(): void
    {
        $container = $this->client->getContainer();
        $id = self::start()->toString();
        $intake = self::claim($this->client, 'intake');

        self::scanner($this->client, 'POST', $id . '/release', ['run' => 1, 'usage' => []], $intake['lease']);

        $row = self::row($id);
        self::assertSame('checking', $row['status']);
        self::assertSame(0, $row['intake_losses'], 'a release is not a lost lease');
        self::assertNull($row['lease_expires_at']);
        self::assertSame(['1:reserve:-1'], self::ledger($id));
        self::assertSame(['1:pre_check:released'], self::usageRows($id));

        // The released intake cannot finish; the next claim has both tries again.
        self::assertSame(409, self::scanner($this->client, 'POST', $id . '/finish', ['run' => 1, 'outcome' => 'refused', 'usage' => []], $intake['lease'])['status']);
        self::assertSame('checking', self::text($id, 'status'));
        // A new claim is a new run number — its usage lands on its own row.
        self::assertSame(2, self::claim($this->client, 'intake')['run']);
    }

    // ---- cancel, mode, entitlement -----------------------------------------

    public function testACancelledRunningScanTellsTheScannerToStopAndCounts(): void
    {
        $build = self::running($this->client);
        $container = $this->client->getContainer();
        $id = $build['id'];

        $this->bus()->dispatch(new CancelAiScan(Uuid::fromString($id), Uuid::fromString(TestDataFixture::USER_1_ID)));

        $beat = self::scanner($this->client, 'POST', $id . '/beat', ['run' => 1, 'seq' => 2, 'usage' => self::usage(2)], $build['lease']);
        self::assertSame(200, $beat['status']);
        self::assertTrue($beat['json']['cancel'] ?? false);
        self::assertSame('cancelled', $beat['json']['status'] ?? null);

        // The aborting scanner still reports what it spent: usage only.
        $finish = self::scanner($this->client, 'POST', $id . '/finish', ['run' => 1, 'outcome' => 'failed', 'error' => 'build', 'usage' => self::usage(3)], $build['lease']);
        self::assertSame(409, $finish['status']);
        self::assertSame(['ok' => false, 'code' => 'stale_run', 'status' => 'cancelled'], $finish['json']);

        self::assertSame(['1:reserve:-1', '1:settle:0'], self::ledger($id), 'cancelled while running counts');
        self::assertSame(['1:pre_check:passed', '1:builder:cancelled'], self::usageRows($id));
        self::assertSame(1000 + 3000, self::row($id)['input_tokens']);
    }

    public function testModeOffFailsEveryScanInProgressWithARefund(): void
    {
        $container = $this->client->getContainer();
        $build = self::running($this->client);
        $queued = self::start()->toString();
        self::passIntake($this->client, $queued);
        $checking = self::start()->toString();

        $this->bus()->dispatch(new SetAiScanRuntime(mode: AiScanMode::Off));

        foreach ([$build['id'], $queued, $checking] as $id) {
            $row = self::row($id);
            self::assertSame('failed', $row['status']);
            self::assertSame('interrupted', $row['error_code']);
            self::assertSame(0, $row['failure_count'], 'the kill switch says nothing about the picture');
            self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($id));
        }

        // A finish of the interrupted run: usage only.
        $late = self::scanner($this->client, 'POST', $build['id'] . '/finish', ['run' => 1, 'outcome' => 'refused', 'usage' => []], $build['lease']);
        self::assertSame(['ok' => false, 'code' => 'stale_run', 'status' => 'failed', 'errorCode' => 'interrupted'], $late['json']);

        self::assertSame(['1:pre_check:passed', '1:builder:interrupted'], self::usageRows($build['id']));

        $beat = self::scanner($this->client, 'POST', $build['id'] . '/beat', ['run' => 1, 'seq' => 2, 'usage' => []], $build['lease']);
        self::assertTrue($beat['json']['cancel'] ?? false);
        self::assertSame('off', $beat['json']['mode'] ?? null);
        self::assertSame('off', $this->claimRaw('build')['reason']);

        // `paused` changes no state.
        $this->bus()->dispatch(new SetAiScanRuntime(mode: AiScanMode::On));
        $waiting = self::start()->toString();
        $this->bus()->dispatch(new SetAiScanRuntime(mode: AiScanMode::Paused));
        self::assertSame('checking', self::row($waiting)['status']);
    }

    public function testSwitchingTheProjectOffCancelsItsWaitingScansWithARefund(): void
    {
        $container = $this->client->getContainer();
        self::entitle(TestDataFixture::PROJECT_1_ID);

        $build = self::running($this->client);
        $queued = self::start()->toString();
        self::passIntake($this->client, $queued);
        $checking = self::start()->toString();

        self::entitle(TestDataFixture::PROJECT_1_ID, false);

        foreach ([$queued, $checking] as $id) {
            self::assertSame('cancelled', self::row($id)['status']);
            self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($id));
        }

        // The running one finishes — and its proposal is delivered.
        self::assertSame('running', self::row($build['id'])['status']);
        self::scanner($this->client, 'POST', $build['id'] . '/render', ['run' => 1, 'seq' => 1, 'design' => self::design()], $build['lease']);
        $finish = self::scanner($this->client, 'POST', $build['id'] . '/finish', ['run' => 1, 'outcome' => 'proposed', 'candidate' => 1, 'report' => self::report(1), 'usage' => []], $build['lease']);
        self::assertSame('ready', $finish['json']['status'] ?? null);
    }

    // ---- the reaper --------------------------------------------------------

    public function testALostIntakeLeaseIsCheckedOnceMoreThenStoppedAsUnusableInput(): void
    {
        $container = $this->client->getContainer();
        $id = self::start()->toString();

        $first = self::claim($this->client, 'intake');
        $this->expireLease($id);
        self::scanner($this->client, 'POST', 'tick', []);

        $row = self::row($id);
        self::assertSame('checking', $row['status']);
        self::assertNull($row['lease_expires_at']);
        self::assertSame(1, $row['intake_losses']);

        // The crashed intake's calls are refused.
        self::assertSame(409, self::scanner($this->client, 'POST', $id . '/finish', ['run' => 1, 'outcome' => 'passed', 'usage' => []], $first['lease'])['status']);

        $second = self::claim($this->client, 'intake');
        self::assertSame(2, $second['run']);
        $this->expireLease($id);
        self::scanner($this->client, 'POST', 'tick', []);

        $row = self::row($id);
        self::assertSame('stopped', $row['status']);
        self::assertSame('unusable_input', $row['error_code']);
        self::assertSame(1, $row['failure_count'], 'an intake that died twice counts against the picture');
        self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($id));
        self::assertSame(['1:pre_check:lease_lost', '2:pre_check:lease_lost'], self::usageRows($id));
    }

    public function testAnOverdueRowIsReapedByAStatusReadWithNoScannerPresent(): void
    {
        $build = self::running($this->client);
        $container = $this->client->getContainer();
        $this->expireLease($build['id']);

        // No scanner call from here on: a user looks at the scan.
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);
        $this->client->request('GET', '/ai-scan/' . $build['id']);

        self::assertResponseIsSuccessful();
        /** @var array{scan: array<string, mixed>} $status */
        $status = json_decode((string) $this->client->getResponse()->getContent(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('queued', $status['scan']['status']);
        self::assertTrue($status['scan']['restarted']);
        self::assertSame(['1:pre_check:passed', '1:builder:lease_lost'], self::usageRows($build['id']));

        // …and the shell's list does the same for the second loss.
        $again = self::claim($this->client, 'build');
        $this->expireLease($again['id']);
        $this->client->request('GET', '/ai-scans/mine');

        self::assertResponseIsSuccessful();
        self::assertSame('failed', self::row($build['id'])['status']);
        self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($build['id']));
    }

    public function testAScanWaitingLongerThanTheQueueTimeoutFailsUncounted(): void
    {
        $container = $this->client->getContainer();
        $queued = self::start()->toString();
        self::passIntake($this->client, $queued);
        $checking = self::start()->toString();
        // Claimed by a live intake: left alone even though it is old.
        $busy = self::start()->toString();

        self::connection()->executeStatement("UPDATE ai_scan SET waiting_since = NOW() - INTERVAL '61 minutes' WHERE id IN (:a, :b, :c)", ['a' => $checking, 'b' => $queued, 'c' => $busy]);
        self::connection()->executeStatement("UPDATE ai_scan SET lease_expires_at = NOW() + INTERVAL '60 seconds', lease_token_hash = 'x' WHERE id = :id", ['id' => $busy]);

        self::assertSame(2, self::scanner($this->client, 'POST', 'tick', [])['json']['reaped'] ?? null);

        foreach ([$checking, $queued] as $id) {
            $row = self::row($id);
            self::assertSame('failed', $row['status']);
            self::assertSame('queue_timeout', $row['error_code']);
            self::assertSame(0, $row['failure_count']);
            self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($id));
        }

        self::assertSame('checking', self::row($busy)['status']);
    }

    // ---- render ------------------------------------------------------------

    public function testARejectedDesignRendersNothingAndStoresNothing(): void
    {
        $build = self::running($this->client);
        $container = $this->client->getContainer();
        $id = $build['id'];

        $answer = self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'seq' => 1, 'design' => self::design(font: 'Comic Sans (Regular)')], $build['lease']);
        self::assertTrue($answer['json']['ok'] ?? false);
        self::assertArrayHasKey('candidate', $answer['json']);
        self::assertNull($answer['json']['candidate']);
        self::assertArrayNotHasKey('image', $answer['json']);
        self::assertContains('font_not_allowed', array_column(self::decoded($answer['json']['issues'] ?? null), 'code'));

        foreach ([['canvas' => ['width' => 6000, 'height' => 100], 'elements' => []], ['elements' => []], 'not a design', null] as $design) {
            $refused = self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'seq' => 2, 'design' => $design], $build['lease']);
            self::assertArrayHasKey('candidate', $refused['json']);
            self::assertNull($refused['json']['candidate']);
            self::assertNotSame([], self::decoded($refused['json']['issues'] ?? null));
        }

        $row = self::row($id);
        self::assertSame('[]', $row['candidates']);
        self::assertSame(0, $row['renders']);

        self::assertSame('invalid_request', self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'design' => self::design()], $build['lease'])['json']['code'] ?? null);
    }

    public function testCandidatesAreCappedAndAFinishNeedsARenderedOne(): void
    {
        $build = self::running($this->client);
        $container = $this->client->getContainer();
        $id = $build['id'];

        foreach ([1, 2, 3] as $n) {
            $answer = self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'seq' => $n, 'design' => self::design('Candidate ' . $n)], $build['lease']);
            self::assertSame($n, $answer['json']['candidate'] ?? null);
        }

        $fourth = self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'seq' => 4, 'design' => self::design()], $build['lease']);
        self::assertSame(['ok' => false, 'code' => 'candidate_limit', 'candidates' => 3], $fourth['json']);

        // Finish naming a candidate that was never rendered here: an inconsistent report.
        $finish = self::scanner($this->client, 'POST', $id . '/finish', ['run' => 1, 'outcome' => 'proposed', 'candidate' => 7, 'report' => self::report(7), 'usage' => []], $build['lease']);
        self::assertSame(['ok' => true, 'applied' => true, 'status' => 'failed', 'code' => 'build'], $finish['json']);
        self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($id));
        self::assertSame(1, self::row($id)['failure_count']);
    }

    /**
     * Rule 3c: the pre-check could not be made, or the provider refused the
     * picture there. Both refunded; "Zkusit znovu" then goes back to
     * `checking` — the intake never finished.
     */
    public function testAnIntakeThatCouldNotCheckFailsRefundedAndARetryChecksAgain(): void
    {
        $id = self::start()->toString();
        $intake = self::claim($this->client, 'intake');

        foreach ([['outcome' => 'failed'], ['outcome' => 'failed', 'error' => 'build'], ['outcome' => 'failed', 'error' => 'timeout']] as $body) {
            self::assertSame('invalid_request', self::scanner($this->client, 'POST', $id . '/finish', ['run' => 1, ...$body], $intake['lease'])['json']['code'] ?? null);
        }

        $failed = self::scanner($this->client, 'POST', $id . '/finish', ['run' => 1, 'outcome' => 'failed', 'error' => 'provider_unavailable', 'usage' => self::usage(1)], $intake['lease']);
        self::assertSame(['ok' => true, 'applied' => true, 'status' => 'failed', 'code' => 'provider_unavailable'], $failed['json']);
        self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($id));
        self::assertSame(['1:pre_check:failed'], self::usageRows($id));
        self::assertSame(0, self::number($id, 'failure_count'));
        self::assertSame('', self::text($id, 'pre_check'));

        self::bus()->dispatch(new RetryAiScan(Uuid::fromString($id), Uuid::fromString(TestDataFixture::USER_1_ID)));

        self::assertSame('checking', self::text($id, 'status'));
        self::assertSame(2, self::number($id, 'try'));
        self::assertSame(['1:reserve:-1', '1:refund:1', '2:reserve:-1'], self::ledger($id));

        // The old intake's token is dead; a new intake claims it and gets both of its tries.
        self::assertSame(409, self::scanner($this->client, 'POST', $id . '/finish', ['run' => 1, 'outcome' => 'refused', 'usage' => []], $intake['lease'])['status']);
        $second = self::claim($this->client, 'intake');
        self::assertSame($id, $second['id']);
        self::assertSame(2, $second['run']);

        $refused = self::scanner($this->client, 'POST', $id . '/finish', ['run' => 2, 'outcome' => 'refused', 'usage' => self::usage(2)], $second['lease']);
        self::assertSame(['ok' => true, 'applied' => true, 'status' => 'refused', 'code' => null], $refused['json']);
        self::assertSame(['1:reserve:-1', '1:refund:1', '2:reserve:-1', '2:refund:1'], self::ledger($id));
        // One usage row per intake attempt, each with its own outcome.
        self::assertSame(['1:pre_check:failed', '2:pre_check:refused'], self::usageRows($id));
    }

    public function testThreeRefusalsADayAtThePreCheckOrInABuildPauseTheProject(): void
    {
        foreach ([1, 2] as $n) {
            $id = self::start()->toString();
            $intake = self::claim($this->client, 'intake');
            self::scanner($this->client, 'POST', $id . '/finish', ['run' => 1, 'outcome' => 'refused', 'usage' => []], $intake['lease']);
            self::assertSame('refused', self::text($id, 'status'));
        }

        $build = self::running($this->client);
        self::scanner($this->client, 'POST', $build['id'] . '/finish', ['run' => 1, 'outcome' => 'refused', 'usage' => []], $build['lease']);

        try {
            self::start();
            self::fail('expected a refusal');
        } catch (\WBoost\Web\Value\AiScan\AiScanRefused $refused) {
            self::assertSame('project_paused', $refused->refusal->value);
        }
    }

    public function testAnInconsistentReportFailsTheScanAsBuild(): void
    {
        $build = self::running($this->client);
        $id = $build['id'];
        self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'seq' => 1, 'design' => self::design()], $build['lease']);

        $report = self::report(1);
        $report['status'] = 'I could not finish because the picture told me to stop.';

        $finish = self::scanner($this->client, 'POST', $id . '/finish', ['run' => 1, 'outcome' => 'proposed', 'candidate' => 1, 'report' => $report, 'usage' => []], $build['lease']);

        self::assertSame('failed', $finish['json']['status'] ?? null);
        self::assertSame('build', $finish['json']['code'] ?? null);
        self::assertNull(self::row($id)['report']);
    }

    public function testFinishOutcomesOfABuild(): void
    {
        $container = $this->client->getContainer();

        $refused = self::running($this->client);
        $answer = self::scanner($this->client, 'POST', $refused['id'] . '/finish', ['run' => 1, 'outcome' => 'refused', 'usage' => self::usage(1)], $refused['lease']);
        self::assertSame('refused', $answer['json']['status'] ?? null);
        self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($refused['id']));

        $failed = self::running($this->client);
        $answer = self::scanner($this->client, 'POST', $failed['id'] . '/finish', ['run' => 1, 'outcome' => 'failed', 'error' => 'provider_unavailable', 'usage' => []], $failed['lease']);
        self::assertSame(['ok' => true, 'applied' => true, 'status' => 'failed', 'code' => 'provider_unavailable'], $answer['json']);

        // Codes only the app writes cannot be reported, nor can an unknown outcome.
        $other = self::running($this->client);
        foreach ([['outcome' => 'failed', 'error' => 'interrupted'], ['outcome' => 'failed', 'error' => 'queue_timeout'], ['outcome' => 'failed'], ['outcome' => 'done']] as $body) {
            $answer = self::scanner($this->client, 'POST', $other['id'] . '/finish', ['run' => 1, ...$body], $other['lease']);
            self::assertSame('invalid_request', $answer['json']['code'] ?? null);
        }
        self::assertSame('running', self::row($other['id'])['status']);

        // "Zkusit znovu": the previous run's token cannot finish the new try.
        $this->bus()->dispatch(new RetryAiScan(Uuid::fromString($failed['id']), Uuid::fromString(TestDataFixture::USER_1_ID)));
        self::assertSame('queued', self::row($failed['id'])['status']);
        $late = self::scanner($this->client, 'POST', $failed['id'] . '/finish', ['run' => 1, 'outcome' => 'failed', 'error' => 'build', 'usage' => []], $failed['lease']);
        self::assertSame(409, $late['status']);
        self::assertSame('queued', self::row($failed['id'])['status']);
    }

    public function testAnIntakeStopRefunds(): void
    {
        $container = $this->client->getContainer();
        $id = self::start()->toString();
        $intake = self::claim($this->client, 'intake');

        self::assertSame('invalid_request', self::scanner($this->client, 'POST', $id . '/finish', ['run' => 1, 'outcome' => 'stopped', 'reason' => 'ugly'], $intake['lease'])['json']['code'] ?? null);

        $stopped = self::scanner($this->client, 'POST', $id . '/finish', ['run' => 1, 'outcome' => 'stopped', 'reason' => 'photo_of_print', 'usage' => self::usage(1)], $intake['lease']);
        self::assertSame(['ok' => true, 'applied' => true, 'status' => 'stopped', 'code' => 'photo_of_print'], $stopped['json']);
        self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($id));
        self::assertSame(['1:pre_check:stopped'], self::usageRows($id));
        self::assertSame(0, self::row($id)['failure_count']);
    }

    public function testTheThirdConcurrentScanRenderIsToldTheRendererIsBusy(): void
    {
        $build = self::running($this->client);
        $id = $build['id'];

        // Two other requests are rendering: each holds one of the two
        // advisory locks on its OWN database session.
        $url = $_SERVER['DATABASE_URL'] ?? null;
        self::assertIsString($url);
        $other = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));

        foreach ([1, 2] as $slot) {
            self::assertTrue($other->fetchOne('SELECT pg_try_advisory_lock(:class, :slot)', ['class' => ScanRenderLimiter::LOCK_CLASS, 'slot' => $slot]));
        }

        try {
            $busy = self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'seq' => 1, 'design' => self::design()], $build['lease']);
            self::assertSame(200, $busy['status']);
            self::assertSame(['ok' => false, 'code' => 'renderer_busy'], $busy['json']);

            $faces = self::scanner($this->client, 'POST', $id . '/faces', ['run' => 1, 'seq' => 2, 'lines' => ['A']], $build['lease']);
            self::assertSame('renderer_busy', $faces['json']['code'] ?? null);
        } finally {
            // One of those requests DIES — no unlock, its session just ends.
            // The database frees the locks by itself.
            $other->close();
        }

        $render = self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'seq' => 1, 'design' => self::design()], $build['lease']);
        self::assertSame(1, $render['json']['candidate'] ?? null, $render['raw']);

        // And a finished render gave its own slot back: both are free again.
        $limiter = self::getContainer()->get(ScanRenderLimiter::class);
        self::assertSame(1, $limiter->acquire());
        $limiter->release(1);
    }

    public function testFacesValidatesItsInput(): void
    {
        $build = self::running($this->client);
        $id = $build['id'];

        foreach ([['lines' => []], ['lines' => 'x'], ['lines' => ['A'], 'faces' => []], ['lines' => ['A'], 'size' => 4], ['lines' => ['A'], 'size' => 4000]] as $body) {
            self::assertSame('invalid_request', self::scanner($this->client, 'POST', $id . '/faces', ['run' => 1, 'seq' => 1, ...$body], $build['lease'])['json']['code'] ?? null);
        }

        // All faces of the project when none are named.
        $all = self::scanner($this->client, 'POST', $id . '/faces', ['run' => 1, 'seq' => 1, 'lines' => ['A']], $build['lease']);
        self::assertTrue($all['json']['ok'] ?? false);
    }

    public function testAnArtifactIsOnlyTakenFromTheIntakeAndWithinTheSizeLimit(): void
    {
        $container = $this->client->getContainer();
        $id = self::start()->toString();
        $intake = self::claim($this->client, 'intake');

        self::assertSame('invalid_request', self::scanner($this->client, 'PUT', $id . '/artifact/working?run=1', '', $intake['lease'])['json']['code'] ?? null);
        self::assertSame(404, self::scanner($this->client, 'PUT', $id . '/artifact/original?run=1', 'x', $intake['lease'])['status']);
        self::assertSame(409, self::scanner($this->client, 'PUT', $id . '/artifact/working?run=2', 'x', $intake['lease'])['status']);

        self::passIntakeArtifacts($this->client, $id, $intake);
        $build = self::claim($this->client, 'build');

        // A builder may not replace the working copy.
        self::assertSame(409, self::scanner($this->client, 'PUT', $id . '/artifact/working?run=1', 'x', $build['lease'])['status']);
    }

    // ---- helpers -----------------------------------------------------------

    private function expireLease(string $scanId): void
    {
        self::connection()->executeStatement(
            // Well in the past: the column has whole seconds, and "a second ago" can round to "now".
            "UPDATE ai_scan SET lease_expires_at = NOW() - INTERVAL '10 seconds' WHERE id = :id",
            ['id' => $scanId],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function claimRaw(string $lane): array
    {
        return self::scanner($this->client, 'POST', 'claim', ['lane' => $lane, 'scannerVersion' => 'test-1', 'instruments' => self::instruments(), 'capacity' => 4])['json'];
    }

    private function runtime(): \WBoost\Web\Services\AiScan\AiScanRuntimeSnapshot
    {
        return self::getContainer()->get(AiScanRuntimeState::class)->get();
    }

    private function templateCount(): int
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // Nothing waits to be inserted either: the transient variant never reached Doctrine.
        self::assertSame([], $entityManager->getUnitOfWork()->getScheduledEntityInsertions());

        return $entityManager->getRepository(Template::class)->count([]) * 1000 + $entityManager->getRepository(TemplateVariant::class)->count([]);
    }
}
