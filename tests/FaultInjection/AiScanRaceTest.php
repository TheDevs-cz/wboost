<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\FaultInjection;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use WBoost\Web\Message\AiScan\RequestAiScan;
use WBoost\Web\MessageHandler\AiScan\RequestAiScanHandler;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Services\AiScan\AiScanPurge;
use WBoost\Web\Services\AiScan\AiScanReaper;
use WBoost\Web\Services\AiScan\AiScans;
use WBoost\Web\Services\AiScan\AiScanTransitions;
use WBoost\Web\Services\AiScan\ScannerApi;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\AiScan\AiScanChannel;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * Fault injection: REAL races, against a real database.
 *
 * Every other AI scan test runs inside DAMA's one transaction on one
 * connection, where nothing can race. Here each racer is a forked PROCESS
 * with its own kernel and its own database connection; they are released at
 * the same instant and the test asserts what must hold whoever wins: exactly
 * one transition, exactly one ledger line.
 *
 * Not part of the default run (group `fault-injection`): it commits for real
 * (and cleans up after itself), needs `pcntl`, and takes a few seconds per
 * case —
 *
 *     docker compose exec web vendor/bin/phpunit --group fault-injection
 *
 * Each case is repeated {@see ROUNDS} times: a race that can go wrong does
 * not go wrong every time.
 */
#[Group('fault-injection')]
final class AiScanRaceTest extends KernelTestCase
{
    use AiScanTesting;
    use RacesProcesses;

    private const int ROUNDS = 6;
    private const string LEASE = 'race-lease-token';
    private const string PROJECT = TestDataFixture::PROJECT_1_ID;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is not available.');
        }

        // Out of DAMA's transaction: racers must see each other's commits.
        StaticDriver::setKeepStaticConnections(false);
    }

    public static function tearDownAfterClass(): void
    {
        self::printTally();
        StaticDriver::setKeepStaticConnections(true);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clean();
        self::switchOn();
        self::consent(TestDataFixture::ADMIN_USER_EMAIL);
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testTwoBuildClaimsHandOutOneJobWhileOneMayRun(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->clean(false);
            $this->scan('queued');
            $this->scan('queued');

            $claim = static fn (ContainerInterface $c): mixed => self::dig(
                self::decoded($c->get(ScannerApi::class)->claim(['lane' => 'build', 'scannerVersion' => 'race', 'instruments' => self::instrumentsOf($c), 'capacity' => 4])->getContent()),
                'job',
                'id',
            );

            $jobs = array_filter($this->race($claim, $claim, $claim));

            self::assertCount(1, $jobs, 'max_running = 1');
            self::assertSame(1, self::rowCount('ai_scan', "status = 'running'"));
            self::assertSame(1, self::rowCount('ai_scan', "status = 'queued'"));
        }
    }

    public function testTwoClaimsNeverGetTheSameScan(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->clean(false);
            self::connection()->executeStatement('UPDATE ai_scan_runtime SET max_running = 4');
            $only = $this->scan('queued');

            $claim = static fn (ContainerInterface $c): mixed => self::dig(
                self::decoded($c->get(ScannerApi::class)->claim(['lane' => 'build', 'scannerVersion' => 'race', 'instruments' => self::instrumentsOf($c), 'capacity' => 4])->getContent()),
                'job',
                'run',
            );

            self::assertSame([1], array_values(array_filter($this->race($claim, $claim, $claim))));
            self::assertSame(1, self::number($only, 'run'));
        }
    }

    public function testAClaimAgainstACancelCountsTheCancelExactlyAsTheStateItFound(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->clean(false);
            $id = $this->scan('queued');

            [$run, $cancelled] = $this->race(
                static fn (ContainerInterface $c): null|int => $c->get(AiScanTransitions::class)->claimBuild($id, hash('sha256', self::LEASE), 'race', 'def'),
                static fn (ContainerInterface $c): bool => $c->get(AiScanTransitions::class)->cancel($id),
            );

            self::assertTrue($cancelled, 'a cancel applies in queued and in running');
            self::assertSame('cancelled', self::text($id, 'status'));
            // Claimed first → it was running → counted; cancelled first → refunded, and the claim got nothing.
            self::assertSame($run === null ? ['1:reserve:-1', '1:refund:1'] : ['1:reserve:-1', '1:settle:0'], self::ledger($id));
        }
    }

    public function testABeatAgainstTheReaperAtTheLeasesLastSecond(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->clean(false);
            $id = $this->scan('running', ['lease_expires_at' => "NOW() - INTERVAL '1 second'"]);

            [$beat, $reaped] = $this->race(
                static fn (ContainerInterface $c): int => $c->get(ScannerApi::class)->beat($id, ['run' => 1, 'seq' => 1, 'usage' => []], self::LEASE)->getStatusCode(),
                static fn (ContainerInterface $c): int => $c->get(AiScanReaper::class)->run(),
            );

            $status = self::text($id, 'status');

            if ($reaped === 1) {
                // The reaper won: back in the queue, restart consumed, the beat is told the run is over.
                self::assertSame('queued', $status);
                self::assertSame(1, self::number($id, 'auto_restart'));
                self::assertSame(409, $beat);
            } else {
                // The beat won: the lease is extended, nothing was consumed.
                self::assertSame('running', $status);
                self::assertSame(0, self::number($id, 'auto_restart'));
                self::assertSame(200, $beat);
                self::assertSame(1, self::rowCount('ai_scan', 'lease_expires_at > NOW()'));
            }

            self::assertSame(['1:reserve:-1'], self::ledger($id), 'neither outcome moves the credit');
        }
    }

    public function testAFinishAgainstACancelSettlesOnce(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->clean(false);
            $id = $this->scan('running');

            [$finished, $cancelled] = $this->race(
                static fn (ContainerInterface $c): bool => $c->get(AiScanTransitions::class)->finishProposed($id, 1, hash('sha256', self::LEASE), 1, ['document' => []], ['status' => 'done'], false),
                static fn (ContainerInterface $c): bool => $c->get(AiScanTransitions::class)->cancel($id),
            );

            self::assertNotSame($finished, $cancelled, 'exactly one of them made its transition');
            self::assertSame($finished ? 'ready' : 'cancelled', self::text($id, 'status'));
            // Delivered, or cancelled while running: counted either way — once.
            self::assertSame(['1:reserve:-1', '1:settle:0'], self::ledger($id));
        }
    }

    public function testTwoDetectionsOfALostLeaseRefundOnce(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->clean(false);
            $id = $this->scan('running', ['lease_expires_at' => "NOW() - INTERVAL '10 seconds'", 'auto_restart' => '1']);

            $reap = static fn (ContainerInterface $c): int => $c->get(AiScanReaper::class)->run();
            $moved = $this->race($reap, $reap, $reap);

            self::assertSame(1, array_sum(array_map(static fn (mixed $count): int => is_int($count) ? $count : 0, $moved)), 'one of them acted');
            self::assertSame('failed', self::text($id, 'status'));
            self::assertSame('interrupted', self::text($id, 'error_code'));
            self::assertSame(1, self::number($id, 'failure_count'));
            self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($id));
        }
    }

    public function testTwoTabsPollingDuringAFailureSeeOneOutcome(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->clean(false);
            $id = $this->scan('running', ['lease_expires_at' => "NOW() - INTERVAL '10 seconds'"]);

            // The status read of two tabs: each meets the overdue row and runs the reaper.
            $poll = static fn (ContainerInterface $c): mixed => self::dig(
                $c->get(AiScans::class)->view($c->get(AiScanRepository::class)->get(Uuid::fromString($id))),
                'status',
            );

            self::assertSame(['queued', 'queued'], $this->race($poll, $poll));
            self::assertSame(1, self::number($id, 'auto_restart'), 'one restart, not two — the second read must not fail the scan');
            self::assertSame(['1:reserve:-1'], self::ledger($id));
            self::assertSame(1, self::rowCount('ai_scan', "jsonb_array_length(events) = 1"));
        }
    }

    /**
     * A run loses its lease while it is still spending: the reaper puts the
     * scan back, another run claims it — and the first run's beat and finish
     * arrive at that very moment, with usage. What it spent is recorded on
     * ITS usage row whoever is first; it changes no state, and the run that
     * holds the scan keeps its own figures.
     */
    public function testAReplacedRunsUsageIsRecordedAndChangesNoState(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->clean(false);
            $id = $this->scan('queued');
            $api = self::getContainer()->get(ScannerApi::class);
            $claim = ['lane' => 'build', 'scannerVersion' => 'race', 'instruments' => self::instrumentsOf(self::getContainer()), 'capacity' => 1];

            $first = self::dig(self::decoded($api->claim($claim)->getContent()), 'job', 'leaseToken');
            self::assertIsString($first);
            $api->beat($id, ['run' => 1, 'seq' => 1, 'usage' => self::usage(1)], $first);
            self::connection()->executeStatement("UPDATE ai_scan SET lease_expires_at = NOW() - INTERVAL '10 seconds' WHERE id = :id", ['id' => $id]);

            // The reaper + the next claim (run 2) against run 1's late beat and late finish.
            [$second, $beat, $finish] = $this->race(
                static fn (ContainerInterface $c): mixed => self::dig(self::decoded($c->get(ScannerApi::class)->claim($claim)->getContent()), 'job', 'leaseToken'),
                static fn (ContainerInterface $c): int => $c->get(ScannerApi::class)->beat($id, ['run' => 1, 'seq' => 2, 'usage' => self::usage(6)], $first)->getStatusCode(),
                static fn (ContainerInterface $c): mixed => self::decoded($c->get(ScannerApi::class)->finish($id, ['run' => 1, 'outcome' => 'failed', 'error' => 'timeout', 'usage' => self::usage(7)], $first)->getContent()),
            );

            $row = self::row($id);
            $ledger = self::ledger($id);
            $usage = [];

            foreach (self::connection()->fetchAllKeyValue("SELECT run, input_tokens FROM ai_usage_event WHERE scan_id = :id AND step = 'builder' ORDER BY run", ['id' => $id]) as $run => $tokens) {
                $usage[(int) $run] = is_numeric($tokens) ? (int) $tokens : -1;
            }

            // Whatever the order: run 1's row holds its last figures (7 × 1000 input tokens).
            self::assertSame(7000, $usage[1] ?? -1, 'usage of the replaced run: ' . json_encode([$second, $beat, $finish, $row['status']]));

            self::won('replaced run', is_string($second) ? 'second claim first' : 'late finish first');

            if (is_string($second)) {
                // The reaper and the second claim came first: run 2 holds the scan.
                self::assertSame('running', $row['status']);
                self::assertSame(2, $row['run']);
                self::assertSame(hash('sha256', $second), $row['lease_token_hash']);
                self::assertSame(0, $usage[2] ?? -1, 'the new run has spent nothing yet');
                self::assertSame(['1:reserve:-1'], $ledger, 'a stale run moves no credit');
                self::assertSame(409, $beat);
                self::assertFalse(self::dig($finish, 'ok'));
            } else {
                // Run 1's finish got there before anybody claimed the scan again: it is the scan's outcome (rule 6) — once.
                self::assertSame('failed', $row['status']);
                self::assertSame('timeout', $row['error_code']);
                self::assertSame(1, $row['run']);
                self::assertSame(['1:reserve:-1', '1:refund:1'], $ledger);
            }

            // The scan's totals are the sums of its usage rows, and the day's spend counts them.
            self::assertSame(array_sum($usage), $row['input_tokens']);
            self::assertSame(0, self::rowCount('ai_usage_event', "scan_id = '" . $id . "' AND closed_at IS NULL AND run = 1"), 'the replaced run is closed');
        }
    }

    public function testAcceptTwiceAndAcceptAgainstDiscardAndAgainstThePurge(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->clean(false);
            $accept = static fn (string $id): \Closure => static fn (ContainerInterface $c): bool => $c->get(AiScanTransitions::class)->accept($id, null, null);

            // accept × 2
            $twice = $this->scan('ready');
            self::assertCount(1, array_filter($this->race($accept($twice), $accept($twice))));
            self::assertSame('accepted', self::text($twice, 'status'));

            // accept vs discard
            $either = $this->scan('ready');
            [$accepted, $discarded] = $this->race(
                $accept($either),
                static fn (ContainerInterface $c): bool => $c->get(AiScanTransitions::class)->discard($either),
            );
            self::assertNotSame($accepted, $discarded);
            self::assertSame($accepted ? 'accepted' : 'discarded', self::text($either, 'status'));

            // accept vs the purge's expiry
            $due = $this->scan('ready', ['expires_at' => "NOW() - INTERVAL '1 hour'", 'proposal' => "'{\"document\": {}}'"]);
            [$accepted, $purged] = $this->race(
                $accept($due),
                static fn (ContainerInterface $c): int => $c->get(AiScanPurge::class)->run()['expired'],
            );

            if ($accepted) {
                // The accept came first: nothing expired, the proposal is intact.
                // (The purge may, after it, have dropped the FILES of the now accepted scan — its retention had run out.)
                self::assertSame(0, $purged);
                self::assertSame('accepted', self::text($due, 'status'));
                self::assertNotSame('', self::text($due, 'proposal'));
            } else {
                self::assertSame(1, $purged);
                self::assertSame('expired', self::text($due, 'status'));
                self::assertSame('', self::text($due, 'proposal'));
            }

            foreach ([$twice, $either, $due] as $id) {
                self::assertSame(['1:reserve:-1'], self::ledger($id));
            }
        }
    }

    public function testTwoStartsInOneProjectAreJudgedOneAfterTheOther(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->clean(false);

            // Three in progress: room for exactly one more (the project's limit is four).
            foreach ([1, 2, 3] as $n) {
                $this->scan('queued');
            }

            $start = static function (ContainerInterface $c): string {
                $path = self::picture();

                try {
                    // What the command bus does: the handler inside one transaction.
                    return $c->get(EntityManagerInterface::class)->getConnection()->transactional(
                        static fn (): string => $c->get(RequestAiScanHandler::class)(new RequestAiScan(
                            scanId: Uuid::uuid7(),
                            requestId: bin2hex(random_bytes(8)),
                            projectId: Uuid::fromString(self::PROJECT),
                            userId: Uuid::fromString(TestDataFixture::ADMIN_USER_ID),
                            kind: AiScanKind::Template,
                            channel: AiScanChannel::Web,
                            filePath: $path,
                            originalName: 'race.png',
                            options: [],
                            consentGiven: true,
                        ))->toString(),
                    );
                } catch (AiScanRefused $refused) {
                    return $refused->refusal->value;
                } finally {
                    @unlink($path);
                }
            };

            $results = $this->race($start, $start, $start);
            $refused = array_values(array_filter($results, static fn (mixed $result): bool => $result === 'project_queue_full'));

            self::assertCount(2, $refused, 'two of the three were refused: ' . json_encode($results));
            self::assertSame(4, self::rowCount('ai_scan', "status IN ('checking', 'queued', 'running')"));
            self::assertSame(4, self::rowCount('ai_credit_ledger', "reason = 'reserve'"));
        }
    }

    public function testTheSameRequestIdTwiceIsOneScan(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $this->clean(false);
            $requestId = 'race-' . $round;

            $start = static function (ContainerInterface $c) use ($requestId): string {
                $path = self::picture();

                try {
                    return $c->get(EntityManagerInterface::class)->getConnection()->transactional(
                        static fn (): string => $c->get(RequestAiScanHandler::class)(new RequestAiScan(
                            Uuid::uuid7(), $requestId, Uuid::fromString(self::PROJECT), Uuid::fromString(TestDataFixture::ADMIN_USER_ID),
                            AiScanKind::Template, AiScanChannel::Web, $path, 'race.png', [], true,
                        ))->toString(),
                    );
                } finally {
                    @unlink($path);
                }
            };

            [$first, $second] = $this->race($start, $start);

            self::assertSame($first, $second, 'both callers were given the same scan');
            self::assertSame(1, self::rowCount('ai_scan'));
            self::assertSame(1, self::rowCount('ai_credit_ledger'));
        }
    }

    /**
     * @return list<string>
     */
    private static function instrumentsOf(ContainerInterface $container): array
    {
        return $container->get(\WBoost\Web\Services\AiScan\AiScanDefinition::class)->requiredInstruments();
    }

    /**
     * A committed scan row in a given state, with its reserve.
     *
     * @param array<string, string> $sql column → SQL expression
     */
    private function scan(string $status, array $sql = []): string
    {
        $id = Uuid::uuid7()->toString();
        $columns = [
            'id' => "'" . $id . "'",
            'request_id' => "'race-" . bin2hex(random_bytes(6)) . "'",
            'project_id' => "'" . self::PROJECT . "'",
            'requested_by_id' => "'" . TestDataFixture::ADMIN_USER_ID . "'",
            'requester_email' => "'" . TestDataFixture::ADMIN_USER_EMAIL . "'",
            'kind' => "'template'",
            'channel' => "'web'",
            'status' => "'" . $status . "'",
            'storage_secret' => "'" . bin2hex(random_bytes(16)) . "'",
            'source_sha256' => "'" . hash('sha256', random_bytes(8)) . "'",
            'source_meta' => "'{}'",
            'options' => "'{}'",
            'pre_check' => "'{}'",
            'created_at' => 'NOW()',
            'started_at' => 'NOW()',
            'waiting_since' => in_array($status, ['checking', 'queued'], true) ? 'NOW()' : 'NULL',
            'run' => $status === 'queued' ? '0' : '1',
            'lease_token_hash' => $status === 'queued' ? 'NULL' : "'" . hash('sha256', self::LEASE) . "'",
            'lease_expires_at' => $status === 'running' ? "NOW() + INTERVAL '100 seconds'" : 'NULL',
            'claimed_at' => $status === 'running' ? 'NOW()' : 'NULL',
            'expires_at' => $status === 'ready' ? "NOW() + INTERVAL '30 days'" : 'NULL',
            ...$sql,
        ];

        self::connection()->executeStatement(sprintf('INSERT INTO ai_scan (%s) VALUES (%s)', implode(', ', array_keys($columns)), implode(', ', $columns)));
        self::connection()->executeStatement(
            "INSERT INTO ai_credit_ledger (id, project_id, scan_id, try, period, delta, reason, cost_micro_usd, created_by, created_at)
             VALUES (gen_random_uuid(), :project, :scan, 1, to_char(NOW(), 'YYYY-MM'), -1, 'reserve', 0, :user, NOW() - INTERVAL '2 days')",
            ['project' => self::PROJECT, 'scan' => $id, 'user' => TestDataFixture::ADMIN_USER_ID],
        );

        return $id;
    }

    /**
     * Everything this suite commits, removed; the runtime row and the
     * project back to their defaults (`$all`) or just the scans.
     */
    private function clean(bool $all = true): void
    {
        $connection = self::connection();
        $connection->executeStatement('DELETE FROM ai_scan');
        $connection->executeStatement('DELETE FROM ai_credit_ledger');
        $connection->executeStatement('DELETE FROM ai_usage_event');
        $connection->executeStatement("UPDATE ai_scan_runtime SET max_running = 1, consecutive_failures = 0, failure_class = NULL, provider_state = 'ok'");

        if ($all) {
            $connection->executeStatement('DELETE FROM ai_scan_runtime');
            $connection->executeStatement('UPDATE project SET ai_scan_enabled = false, ai_scan_monthly_limit = 10, ai_scan_enabled_at = NULL, ai_scan_enabled_by_id = NULL');
            $connection->executeStatement('UPDATE "user" SET ai_scan_consent_version = NULL, ai_scan_consent_at = NULL');
        }

        (new \Symfony\Component\Filesystem\Filesystem())->remove('/tmp/wboost-test-private/ai-scan');
    }
}
