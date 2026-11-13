<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

use WBoost\Web\Value\AiScan\AiScanEvent as E;
use WBoost\Web\Value\AiScan\AiScanStatus as S;

/**
 * The transition table as data: for every event, the states it applies to and
 * the state it leads to. Everything not listed is refused — in particular
 * nothing leaves `accepted`, `discarded` or `expired`.
 *
 * The numbers are the rule numbers of architecture.md §5.2.
 */
final class AiScanTransitionTable
{
    /**
     * @return array<string, array<string, S>> event value → from-state value → to-state
     */
    public static function table(): array
    {
        return [
            // 1a, 3d — the intake takes / gives back the lease; the state stays.
            E::IntakeClaim->value => [S::Checking->value => S::Checking],
            E::IntakeRelease->value => [S::Checking->value => S::Checking],
            // 2, 3
            E::IntakePassed->value => [S::Checking->value => S::Queued],
            E::IntakeStopped->value => [S::Checking->value => S::Stopped],
            // 3c — the pre-check could not be made / the provider refused the picture
            E::IntakeFailed->value => [S::Checking->value => S::Failed],
            E::IntakeRefused->value => [S::Checking->value => S::Refused],
            // 3a, 3b
            E::IntakeLeaseLost->value => [S::Checking->value => S::Checking],
            E::IntakeLeaseLostAgain->value => [S::Checking->value => S::Stopped],
            // 4
            E::BuildClaim->value => [S::Queued->value => S::Running],
            // 5, 6 — `queued` is the late finish of a run the reaper already put back
            E::FinishProposed->value => [S::Running->value => S::Ready, S::Queued->value => S::Ready],
            E::FinishFailed->value => [S::Running->value => S::Failed, S::Queued->value => S::Failed],
            E::FinishRefused->value => [S::Running->value => S::Refused, S::Queued->value => S::Refused],
            // 7, 6
            E::BuildLeaseLost->value => [S::Running->value => S::Queued],
            E::BuildLeaseLostAgain->value => [S::Running->value => S::Failed],
            E::BuildRelease->value => [S::Running->value => S::Queued],
            E::BuildReleaseAgain->value => [S::Running->value => S::Failed],
            // 8, 9
            E::Cancel->value => [
                S::Checking->value => S::Cancelled,
                S::Queued->value => S::Cancelled,
                S::Running->value => S::Cancelled,
            ],
            // 10
            E::EntitlementOff->value => [S::Checking->value => S::Cancelled, S::Queued->value => S::Cancelled],
            // 11
            E::QueueTimeout->value => [S::Checking->value => S::Failed, S::Queued->value => S::Failed],
            // 12, 13
            E::ContinueAnyway->value => [S::Stopped->value => S::Queued],
            E::Retry->value => [S::Failed->value => S::Queued],
            // 13, when the intake never finished (no stored pre-check)
            E::RetryCheck->value => [S::Failed->value => S::Checking],
            // 13a
            E::ModeOff->value => [
                S::Checking->value => S::Failed,
                S::Queued->value => S::Failed,
                S::Running->value => S::Failed,
            ],
            // 14
            E::Accept->value => [S::Ready->value => S::Accepted],
            // 15, 16
            E::Discard->value => self::fromEndedUnused(S::Discarded),
            E::Expire->value => self::fromEndedUnused(S::Expired),
        ];
    }

    public static function target(S $from, E $event): null|S
    {
        return self::table()[$event->value][$from->value] ?? null;
    }

    /**
     * @return list<S>
     */
    public static function sources(E $event): array
    {
        return array_map(
            static fn (string $state): S => S::from($state),
            array_keys(self::table()[$event->value] ?? []),
        );
    }

    /**
     * @return array<string, S>
     */
    private static function fromEndedUnused(S $to): array
    {
        $map = [];

        foreach (S::endedUnused() as $state) {
            $map[$state->value] = $to;
        }

        return $map;
    }
}
