<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\AiScan;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Services\AiScan\AiScanCredits;
use WBoost\Web\Services\AiScan\AiScanTransitions;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\AiScan\AiScanErrorCode;
use WBoost\Web\Value\AiScan\AiScanEvent as E;
use WBoost\Web\Value\AiScan\AiScanStatus as S;
use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\AiScan\AiScanStopReason;
use WBoost\Web\Value\AiScan\AiScanTransitionTable;

/**
 * The transition service against the database: for EVERY state × event the
 * conditional update either makes exactly the move the table names, or
 * changes nothing — and the ledger moves with it exactly once.
 */
final class AiScanTransitionsTest extends KernelTestCase
{
    use AiScanTesting;

    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** What each event does to the try's credit when it applies (from → line). */
    private const array LEDGER = [
        'intake_stopped' => 'refund',
        'intake_failed' => 'refund',
        'intake_refused' => 'refund',
        'intake_lease_lost_again' => 'refund',
        'finish_proposed' => 'settle',
        'finish_failed' => 'refund',
        'finish_refused' => 'refund',
        'build_lease_lost_again' => 'refund',
        'build_release_again' => 'refund',
        'entitlement_off' => 'refund',
        'queue_timeout' => 'refund',
        'mode_off' => 'refund',
    ];

    /**
     * @return iterable<string, array{S, E}>
     */
    public static function everyStateAndEvent(): iterable
    {
        foreach (S::cases() as $state) {
            foreach (E::cases() as $event) {
                yield sprintf('%s × %s', $state->value, $event->value) => [$state, $event];
            }
        }
    }

    #[DataProvider('everyStateAndEvent')]
    public function testEveryStateTimesEvent(S $from, E $event): void
    {
        $scanId = $this->scanIn($from, $event);
        $expected = AiScanTransitionTable::target($from, $event);

        $applied = $this->fire($event, $scanId);
        $row = self::row($scanId);

        if ($expected === null) {
            self::assertFalse($applied, 'refused');
            self::assertSame($from->value, $row['status'], 'nothing moved');
            self::assertSame(['1:reserve:-1'], self::ledger($scanId), 'the credit did not move');

            return;
        }

        self::assertTrue($applied);
        self::assertSame($expected->value, $row['status']);

        $line = match (true) {
            // A cancel counts only when the scan was running.
            $event === E::Cancel => $from === S::Running ? 'settle' : 'refund',
            default => self::LEDGER[$event->value] ?? null,
        };

        $ledger = match (true) {
            $line === 'settle' => ['1:reserve:-1', '1:settle:0'],
            $line === 'refund' => ['1:reserve:-1', '1:refund:1'],
            // A new start reserves again.
            in_array($event, [E::Retry, E::RetryCheck, E::ContinueAnyway], true) => ['1:reserve:-1', '2:reserve:-1'],
            default => ['1:reserve:-1'],
        };

        self::assertSame($ledger, self::ledger($scanId));

        // Firing the same event again never moves the credit a second time.
        $this->fire($event, $scanId);
        $closing = array_filter(
            self::ledger($scanId),
            static fn (string $entry): bool => str_starts_with($entry, '1:') && !str_contains($entry, 'reserve'),
        );
        self::assertCount($line === null ? 0 : 1, $closing, 'exactly one settle or refund per reserve — or none');

        // An ending stamps the end and the retention; a move back to work clears them.
        if (in_array($expected, [S::Ready, S::Failed, S::Stopped, S::Refused, S::Cancelled], true) && $from->isInProgress()) {
            self::assertNotNull($row['finished_at']);
            self::assertNotNull($row['expires_at']);
            self::assertNull($row['waiting_since']);
        }

        if ($expected->isInProgress() && !$from->isInProgress()) {
            self::assertNull($row['finished_at']);
            self::assertNull($row['expires_at']);
            self::assertNull($row['error_code']);
            self::assertNotNull($row['waiting_since']);
        }

        // Every state change is in the scan's history.
        if ($from !== $expected) {
            $events = self::decoded($row['events']);
            $last = end($events);
            self::assertSame([$event->value, $from->value, $expected->value], [self::dig($last, 'event'), self::dig($last, 'from'), self::dig($last, 'to')]);
        }
    }

    public function testASettleNeverFollowsARefundNorTheOtherWayRound(): void
    {
        $credits = self::getContainer()->get(AiScanCredits::class);
        $scanId = $this->scanIn(S::Running, E::FinishProposed);

        self::assertTrue($credits->refund($scanId, 1));
        self::assertFalse($credits->settle($scanId, 1));
        self::assertFalse($credits->refund($scanId, 1));
        self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($scanId));

        // A reserve is written once however often it is asked for.
        $credits->reserve(TestDataFixture::PROJECT_1_ID, $scanId, 1, null);
        self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($scanId));

        // No reserve, nothing to close.
        self::assertFalse($credits->settle($scanId, 5));
    }

    public function testARefundLandsInTheReservesMonth(): void
    {
        $container = self::getContainer();
        $credits = $container->get(AiScanCredits::class);
        $scanId = $this->scanIn(S::Running, E::FinishFailed);

        // The scan was started last month…
        self::connection()->executeStatement("UPDATE ai_credit_ledger SET period = '2026-10', created_at = '2026-10-31 22:59:00' WHERE scan_id = :id", ['id' => $scanId]);
        // …and fails now.
        $this->transitions()->finishFailed($scanId, 1, self::HASH, AiScanErrorCode::Timeout);

        self::assertEquals(
            ['reserve' => '2026-10', 'refund' => '2026-10'],
            self::connection()->fetchAllKeyValue('SELECT reason, period FROM ai_credit_ledger WHERE scan_id = :id', ['id' => $scanId]),
        );
        self::assertSame(0, $credits->usedInMonth(TestDataFixture::PROJECT_1_ID, new \DateTimeImmutable('2026-10-15 12:00:00')));
        self::assertSame(0, $credits->usedInMonth(TestDataFixture::PROJECT_1_ID, new \DateTimeImmutable('now')));
    }

    public function testACountedScanStaysCountedWhateverTheUserDoesWithIt(): void
    {
        $container = self::getContainer();
        $credits = $container->get(AiScanCredits::class);
        $now = new \DateTimeImmutable('now');

        $delivered = $this->scanIn(S::Ready, E::Discard);
        $this->transitions()->discard($delivered);

        $accepted = $this->scanIn(S::Ready, E::Accept);
        $this->transitions()->accept($accepted, null, null);

        $expired = $this->scanIn(S::Ready, E::Expire);
        $this->transitions()->expire($expired);

        // Three reserves, none given back — and `countedTotal` reads settles only.
        self::assertSame(3, $credits->usedInMonth(TestDataFixture::PROJECT_1_ID, $now));
        self::assertSame(0, $credits->countedTotal(TestDataFixture::PROJECT_1_ID));

        $row = self::row($expired);
        self::assertNull($row['proposal']);
        self::assertNull($row['expires_at']);

        // One retention date per scan: accepting does not move it.
        self::assertNotNull(self::row($accepted)['expires_at']);
        self::assertLessThan('2100', self::text($accepted, 'expires_at'));
    }

    public function testFailuresThatSayNothingAboutThePictureDoNotCountAgainstIt(): void
    {
        $container = self::getContainer();

        foreach ([[AiScanErrorCode::Timeout, 1], [AiScanErrorCode::Build, 1], [AiScanErrorCode::ProviderUnavailable, 0], [AiScanErrorCode::RendererUnavailable, 0]] as [$code, $count]) {
            $scanId = $this->scanIn(S::Running, E::FinishFailed);
            $this->transitions()->finishFailed($scanId, 1, self::HASH, $code);
            self::assertSame($count, self::row($scanId)['failure_count'], $code->value);
        }

        $timedOut = $this->scanIn(S::Queued, E::QueueTimeout);
        $this->transitions()->queueTimeout($timedOut);
        self::assertSame(0, self::row($timedOut)['failure_count']);

        $interrupted = $this->scanIn(S::Running, E::ModeOff);
        $this->transitions()->interruptForModeOff($interrupted);
        self::assertSame(0, self::row($interrupted)['failure_count']);

        // A pre-check that could not be made, or a refusal there: not the picture's fault either.
        foreach ([E::IntakeFailed, E::IntakeRefused] as $event) {
            $scanId = $this->scanIn(S::Checking, $event);
            self::assertTrue($this->fire($event, $scanId));
            self::assertSame(0, self::row($scanId)['failure_count']);
        }

        // A run that died twice does count — once.
        $died = $this->scanIn(S::Running, E::BuildLeaseLostAgain);
        $this->transitions()->buildLeaseLost($died);
        self::assertSame('interrupted', self::row($died)['error_code']);
        self::assertSame(1, self::row($died)['failure_count']);

        // And so does an intake that died twice.
        $unusable = $this->scanIn(S::Checking, E::IntakeLeaseLostAgain);
        $this->transitions()->intakeLeaseLost($unusable);
        self::assertSame(1, self::row($unusable)['failure_count']);
    }

    public function testTheHistoryKeepsTheLastTwoHundredEvents(): void
    {
        $container = self::getContainer();
        $scanId = $this->scanIn(S::Running, E::Cancel);
        $events = json_encode(array_map(static fn (int $n): array => ['event' => 'old', 'n' => $n], range(1, 200)), JSON_THROW_ON_ERROR);
        self::connection()->executeStatement('UPDATE ai_scan SET events = CAST(:events AS JSONB) WHERE id = :id', ['events' => $events, 'id' => $scanId]);

        $this->transitions()->cancel($scanId);

        $stored = self::decoded(self::text($scanId, 'events'));
        self::assertCount(200, $stored);
        self::assertSame(2, self::dig($stored, 0, 'n'));
        self::assertSame('cancel', self::dig($stored, 199, 'event'));
    }

    public function testThreeFailuresOfOneClassInARowPauseTheFeature(): void
    {
        $container = self::getContainer();

        foreach ([1, 2] as $n) {
            $this->transitions()->finishFailed($this->scanIn(S::Running, E::FinishFailed), 1, self::HASH, AiScanErrorCode::ProviderUnavailable);
        }

        // A different class starts a new streak; a delivered proposal ends it.
        $this->transitions()->finishFailed($this->scanIn(S::Running, E::FinishFailed), 1, self::HASH, AiScanErrorCode::Build);
        self::assertSame('on', self::connection()->fetchOne('SELECT mode FROM ai_scan_runtime'));
        self::assertSame(1, self::connection()->fetchOne('SELECT consecutive_failures FROM ai_scan_runtime'));

        $this->transitions()->finishProposed($this->scanIn(S::Running, E::FinishProposed), 1, self::HASH, 1, [], [], false);
        self::assertSame(0, self::connection()->fetchOne('SELECT consecutive_failures FROM ai_scan_runtime'));

        foreach ([1, 2, 3] as $n) {
            $this->transitions()->finishFailed($this->scanIn(S::Running, E::FinishFailed), 1, self::HASH, AiScanErrorCode::RendererUnavailable);
        }

        self::assertSame('paused', self::connection()->fetchOne('SELECT mode FROM ai_scan_runtime'));
        self::assertSame('renderer_unavailable', self::connection()->fetchOne('SELECT failure_class FROM ai_scan_runtime'));

        // Switching on by hand closes the breaker.
        self::switchOn();
        self::assertSame(0, self::connection()->fetchOne('SELECT consecutive_failures FROM ai_scan_runtime'));
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * A scan in `$state`, shaped so that `$event`'s own guard (lease, run,
     * retention, tries) holds — what decides is the STATE.
     */
    private function scanIn(S $state, E $event): string
    {
        $container = self::getContainer();
        self::switchOn();
        $scanId = self::start(TestDataFixture::PROJECT_1_ID, TestDataFixture::ADMIN_USER_EMAIL)->toString();

        $set = [
            'status' => $state->value,
            'run' => 1,
            'intake_tries' => 1,
            'lease_token_hash' => self::HASH,
            'lease_expires_at' => null,
            'waiting_since' => null,
            'finished_at' => null,
            'expires_at' => null,
            'error_code' => null,
            'auto_restart' => 0,
            'releases' => 0,
        ];

        if ($state->isInProgress()) {
            $set['waiting_since'] = $state === S::Running ? null : 'now';
        } else {
            $set['finished_at'] = 'now';
            $set['expires_at'] = 'future';
        }

        // A claimed scan holds a live lease — except for the events that are
        // ABOUT its absence.
        if (in_array($state, [S::Checking, S::Running], true)) {
            $set['lease_expires_at'] = 'future';
        }

        match ($event) {
            E::IntakeClaim, E::BuildClaim => $set['lease_expires_at'] = null,
            E::IntakeLeaseLost => [$set['lease_expires_at'], $set['intake_losses']] = ['past', 0],
            E::IntakeLeaseLostAgain => [$set['lease_expires_at'], $set['intake_losses']] = ['past', 1],
            E::BuildLeaseLost => [$set['lease_expires_at'], $set['auto_restart']] = ['past', 0],
            E::BuildLeaseLostAgain => [$set['lease_expires_at'], $set['auto_restart']] = ['past', 1],
            E::BuildReleaseAgain => $set['releases'] = AiScanLimits::MAX_BUILD_RELEASES,
            E::QueueTimeout => [$set['waiting_since'], $set['lease_expires_at']] = ['long ago', null],
            E::Expire => $set['expires_at'] = 'past',
            E::ContinueAnyway => $set['error_code'] = AiScanStopReason::PhotoOfPrint->value,
            // "Zkusit znovu" goes to the queue when the intake had finished…
            E::Retry => $set['pre_check'] = '{}',
            // …and back to `checking` when it never did.
            E::RetryCheck => $set['pre_check'] = null,
            default => null,
        };

        $times = ['now' => 'NOW()', 'future' => "NOW() + INTERVAL '1 hour'", 'past' => "NOW() - INTERVAL '1 minute'", 'long ago' => "NOW() - INTERVAL '2 hours'"];
        $assignments = [];
        $params = ['id' => $scanId];

        foreach ($set as $column => $value) {
            if (is_string($value) && isset($times[$value])) {
                $assignments[] = sprintf('%s = %s', $column, $times[$value]);
            } else {
                $assignments[] = $column === 'pre_check'
                    ? 'pre_check = CAST(:pre_check AS JSONB)'
                    : sprintf('%s = :%s', $column, $column);
                $params[$column] = $value;
            }
        }

        self::connection()->executeStatement(sprintf('UPDATE ai_scan SET %s WHERE id = :id', implode(', ', $assignments)), $params);

        return $scanId;
    }

    private function fire(E $event, string $id): bool
    {
        $t = $this->transitions();

        return match ($event) {
            E::IntakeClaim => $t->claimIntake($id, self::HASH, 'test') !== null,
            E::IntakePassed => $t->intakePassed($id, 1, self::HASH, null, []),
            E::IntakeStopped => $t->intakeStopped($id, 1, self::HASH, AiScanStopReason::BlurryOrSmall, null),
            E::IntakeFailed => $t->intakeFailed($id, 1, self::HASH),
            E::IntakeRefused => $t->intakeRefused($id, 1, self::HASH),
            E::IntakeRelease => $t->releaseIntake($id, 1, self::HASH),
            E::IntakeLeaseLost, E::IntakeLeaseLostAgain => $t->intakeLeaseLost($id),
            E::BuildClaim => $t->claimBuild($id, self::HASH, 'test', 'def') !== null,
            E::FinishProposed => $t->finishProposed($id, 1, self::HASH, 1, ['document' => []], ['status' => 'complete'], false),
            E::FinishFailed => $t->finishFailed($id, 1, self::HASH, AiScanErrorCode::Timeout),
            E::FinishRefused => $t->finishRefused($id, 1, self::HASH),
            E::BuildLeaseLost, E::BuildLeaseLostAgain => $t->buildLeaseLost($id),
            E::BuildRelease, E::BuildReleaseAgain => $t->releaseBuild($id, 1, self::HASH),
            E::Cancel => $t->cancel($id),
            E::EntitlementOff => $t->cancelForEntitlementOff($id),
            E::QueueTimeout => $t->queueTimeout($id),
            E::ContinueAnyway => $t->continueAnyway($id, null),
            E::Retry, E::RetryCheck => $t->retry($id, null),
            E::ModeOff => $t->interruptForModeOff($id),
            E::Accept => $t->accept($id, null, null),
            E::Discard => $t->discard($id),
            E::Expire => $t->expire($id),
        };
    }

    private function transitions(): AiScanTransitions
    {
        $transitions = self::getContainer()->get(AiScanTransitions::class);

        return $transitions;
    }
}
