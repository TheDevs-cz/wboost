<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value\AiScan;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\AiScan\AiScanEvent as E;
use WBoost\Web\Value\AiScan\AiScanStatus as S;
use WBoost\Web\Value\AiScan\AiScanTransitionTable;

/**
 * The transition table as data (architecture.md §5.2): for EVERY state × event
 * pair the table either names the next state or refuses. The expectations
 * below are written out by hand from the document — not derived from the
 * table — so a slip in either shows.
 */
final class AiScanTransitionTableTest extends TestCase
{
    /**
     * event → [from → to]; everything absent must be refused.
     *
     * @return array<string, array<string, string>>
     */
    private static function expected(): array
    {
        $ended = ['ready', 'failed', 'stopped', 'refused', 'cancelled'];

        return [
            'intake_claim' => ['checking' => 'checking'],
            'intake_release' => ['checking' => 'checking'],
            'intake_passed' => ['checking' => 'queued'],
            'intake_stopped' => ['checking' => 'stopped'],
            'intake_failed' => ['checking' => 'failed'],
            'intake_refused' => ['checking' => 'refused'],
            'intake_lease_lost' => ['checking' => 'checking'],
            'intake_lease_lost_again' => ['checking' => 'stopped'],
            'build_claim' => ['queued' => 'running'],
            'finish_proposed' => ['running' => 'ready', 'queued' => 'ready'],
            'finish_failed' => ['running' => 'failed', 'queued' => 'failed'],
            'finish_refused' => ['running' => 'refused', 'queued' => 'refused'],
            'build_lease_lost' => ['running' => 'queued'],
            'build_lease_lost_again' => ['running' => 'failed'],
            'build_release' => ['running' => 'queued'],
            'build_release_again' => ['running' => 'failed'],
            'cancel' => ['checking' => 'cancelled', 'queued' => 'cancelled', 'running' => 'cancelled'],
            'entitlement_off' => ['checking' => 'cancelled', 'queued' => 'cancelled'],
            'queue_timeout' => ['checking' => 'failed', 'queued' => 'failed'],
            'continue' => ['stopped' => 'queued'],
            'retry' => ['failed' => 'queued'],
            'retry_check' => ['failed' => 'checking'],
            'mode_off' => ['checking' => 'failed', 'queued' => 'failed', 'running' => 'failed'],
            'accept' => ['ready' => 'accepted'],
            'discard' => array_fill_keys($ended, 'discarded'),
            'expire' => array_fill_keys($ended, 'expired'),
        ];
    }

    /**
     * @return iterable<string, array{S, E, null|S}>
     */
    public static function everyStateAndEvent(): iterable
    {
        $expected = self::expected();

        foreach (S::cases() as $state) {
            foreach (E::cases() as $event) {
                $to = $expected[$event->value][$state->value] ?? null;

                yield sprintf('%s × %s', $state->value, $event->value) => [$state, $event, $to === null ? null : S::from($to)];
            }
        }
    }

    #[DataProvider('everyStateAndEvent')]
    public function testEveryStateTimesEventTransitionsOrIsRefused(S $from, E $event, null|S $to): void
    {
        self::assertSame($to, AiScanTransitionTable::target($from, $event));
    }

    public function testEveryEventIsInTheTableAndTheExpectationsCoverEveryEvent(): void
    {
        $events = array_map(static fn (E $event): string => $event->value, E::cases());

        self::assertEqualsCanonicalizing($events, array_keys(AiScanTransitionTable::table()));
        self::assertEqualsCanonicalizing($events, array_keys(self::expected()));
    }

    public function testNothingLeavesAnAbsorbingState(): void
    {
        foreach ([S::Accepted, S::Discarded, S::Expired] as $state) {
            self::assertTrue($state->isAbsorbing());

            foreach (E::cases() as $event) {
                self::assertNull(AiScanTransitionTable::target($state, $event), sprintf('%s must absorb %s', $state->value, $event->value));
            }
        }

        foreach (S::cases() as $state) {
            if (!in_array($state, [S::Accepted, S::Discarded, S::Expired], true)) {
                self::assertFalse($state->isAbsorbing());
            }
        }
    }

    public function testEveryStateIsReachableAndEveryNonAbsorbingStateHasAnExit(): void
    {
        $reached = [S::Checking->value => true];
        $exits = [];

        foreach (AiScanTransitionTable::table() as $moves) {
            foreach ($moves as $from => $to) {
                $reached[$to->value] = true;

                if ($from !== $to->value) {
                    $exits[$from] = true;
                }
            }
        }

        foreach (S::cases() as $state) {
            self::assertArrayHasKey($state->value, $reached, $state->value . ' is unreachable');
            self::assertSame(!$state->isAbsorbing(), isset($exits[$state->value]), $state->value);
        }
    }

    public function testInProgressAndEndedUnusedPartitionTheLiveStates(): void
    {
        self::assertSame([S::Checking, S::Queued, S::Running], S::inProgress());
        self::assertSame([S::Ready, S::Failed, S::Stopped, S::Refused, S::Cancelled], S::endedUnused());

        foreach (S::cases() as $state) {
            self::assertSame(
                1,
                (int) $state->isInProgress() + (int) $state->isEndedUnused() + (int) $state->isAbsorbing(),
                $state->value . ' belongs to exactly one group',
            );
        }
    }
}
