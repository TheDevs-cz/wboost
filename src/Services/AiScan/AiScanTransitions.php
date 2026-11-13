<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use WBoost\Web\Value\AiScan\AiLedgerReason;
use WBoost\Web\Value\AiScan\AiScanErrorCode;
use WBoost\Web\Value\AiScan\AiScanEvent;
use WBoost\Web\Value\AiScan\AiScanLane;
use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\AiScan\AiScanStatus;
use WBoost\Web\Value\AiScan\AiScanStopReason;
use WBoost\Web\Value\AiScan\AiScanTransitionTable;
use WBoost\Web\Value\AiScan\AiUsageOutcome;
use WBoost\Web\Value\AiScan\AiUsageStep;

/**
 * Every change of a scan's state (architecture.md §5.2), each as ONE
 * conditional update:
 *
 *     UPDATE ai_scan SET … WHERE id = ? AND status IN (…) [AND run = ?] [AND …]
 *
 * A transition happened only when that statement changed one row. Everything
 * that belongs to it — the ledger line, the closed usage row, the event in
 * the scan's history — is written in the same database transaction and only
 * then. So however many callers race (a finish against a cancel, two tabs,
 * the reaper against a heartbeat), exactly one wins and the credit moves
 * exactly once.
 *
 * Which states an event applies to comes from {@see AiScanTransitionTable} —
 * the same data the unit test walks — never from a list repeated here.
 *
 * Methods return whether THIS call made the transition. They take ids, not
 * entities: a loaded {@see \WBoost\Web\Entity\AiScan} is a snapshot.
 */
final readonly class AiScanTransitions
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private AiScanCredits $credits,
        private RecordAiUsage $usage,
        private AiScanRuntimeState $runtime,
        private AiScanNotifications $notifications,
    ) {
    }

    // ---- intake lane -------------------------------------------------------

    /** The intake takes an unclaimed `checking` scan. */
    public function claimIntake(string $id, string $leaseTokenHash, null|string $scannerVersion): null|int
    {
        return $this->atomically(function () use ($id, $leaseTokenHash, $scannerVersion): null|int {
            $tries = $this->apply(
                $id,
                AiScanEvent::IntakeClaim,
                [
                    'lease_token_hash = :hash',
                    'lease_expires_at = :lease',
                    'claimed_at = :now',
                    'intake_tries = intake_tries + 1',
                    'beat_seq = 0',
                    "stage = 'check'",
                    'stage_started_at = :now',
                    'scanner_version = COALESCE(:scanner, scanner_version)',
                ],
                ['hash' => $leaseTokenHash, 'lease' => $this->in(AiScanLimits::INTAKE_LEASE_SECONDS), 'scanner' => $scannerVersion],
                'lease_expires_at IS NULL',
                returning: 'intake_tries',
                history: false,
            );

            if ($tries === null) {
                return null;
            }

            $this->usage->open($id, $tries, AiUsageStep::PreCheck, $leaseTokenHash);

            return $tries;
        });
    }

    /**
     * Rule 2: the pre-check passed.
     *
     * @param null|array<string, mixed> $preCheck
     * @param array<string, mixed> $sourceMeta facts the scanner read while normalising (merged)
     */
    public function intakePassed(string $id, int $run, string $leaseTokenHash, null|array $preCheck, array $sourceMeta): bool
    {
        return $this->atomically(function () use ($id, $run, $leaseTokenHash, $preCheck, $sourceMeta): bool {
            $done = $this->apply(
                $id,
                AiScanEvent::IntakePassed,
                [
                    'lease_expires_at = NULL',
                    'claimed_at = NULL',
                    'stage = NULL',
                    'stage_started_at = NULL',
                    // Never null once the intake passed: a stored pre-check is
                    // what tells a retry that the working copy exists.
                    "pre_check = COALESCE(CAST(:preCheck AS JSONB), '{}')",
                    'source_meta = source_meta || CAST(:meta AS JSONB)',
                ],
                ['preCheck' => self::json($preCheck), 'meta' => self::json($sourceMeta === [] ? new \stdClass() : $sourceMeta), 'run' => $run, 'hash' => $leaseTokenHash],
                'intake_tries = :run AND lease_token_hash = :hash AND lease_expires_at IS NOT NULL',
            ) !== null;

            if ($done) {
                $this->usage->close($id, $run, AiUsageStep::PreCheck, AiUsageOutcome::Passed);
            }

            return $done;
        });
    }

    /**
     * Rule 3: the pre-check (or normalising) says no.
     *
     * @param null|array<string, mixed> $preCheck
     */
    public function intakeStopped(string $id, int $run, string $leaseTokenHash, AiScanStopReason $reason, null|array $preCheck): bool
    {
        return $this->atomically(function () use ($id, $run, $leaseTokenHash, $reason, $preCheck): bool {
            $try = $this->apply(
                $id,
                AiScanEvent::IntakeStopped,
                // Stored (never null): the intake DID finish — a later retry goes to the queue.
                [...$this->ended($reason->value), "pre_check = COALESCE(CAST(:preCheck AS JSONB), '{}')"],
                ['preCheck' => self::json($preCheck), 'run' => $run, 'hash' => $leaseTokenHash],
                'intake_tries = :run AND lease_token_hash = :hash AND lease_expires_at IS NOT NULL',
                code: $reason->value,
            );

            if ($try === null) {
                return false;
            }

            $this->credits->refund($id, $try);
            $this->usage->close($id, $run, AiUsageStep::PreCheck, AiUsageOutcome::Stopped, $reason->value);

            return true;
        });
    }

    /**
     * Rule 3c: the pre-check could not be made (the provider was unavailable
     * and the one retry is used up). Refunded; says nothing about the picture.
     */
    public function intakeFailed(string $id, int $run, string $leaseTokenHash): bool
    {
        return $this->endIntake($id, $run, $leaseTokenHash, AiScanEvent::IntakeFailed, AiScanErrorCode::ProviderUnavailable->value, AiUsageOutcome::Failed);
    }

    /** Rule 3c: the provider refused the picture at the pre-check. Refunded; counts toward the project's three a day. */
    public function intakeRefused(string $id, int $run, string $leaseTokenHash): bool
    {
        return $this->endIntake($id, $run, $leaseTokenHash, AiScanEvent::IntakeRefused, null, AiUsageOutcome::Refused);
    }

    /** Rule 3d — the scanner is shutting down mid-intake (or the provider is blocked): back to unclaimed, the try is given back. */
    public function releaseIntake(string $id, int $run, string $leaseTokenHash): bool
    {
        return $this->atomically(function () use ($id, $run, $leaseTokenHash): bool {
            $done = $this->apply(
                $id,
                AiScanEvent::IntakeRelease,
                // The lease goes back and no loss is counted — "the try is given
                // back". The claim number is NOT reused: the next intake gets
                // its own usage row.
                ['lease_expires_at = NULL', 'claimed_at = NULL', 'stage = NULL', 'stage_started_at = NULL', 'lease_token_hash = NULL'],
                ['run' => $run, 'hash' => $leaseTokenHash],
                'intake_tries = :run AND lease_token_hash = :hash AND lease_expires_at IS NOT NULL',
                history: false,
            ) !== null;

            if ($done) {
                $this->usage->close($id, $run, AiUsageStep::PreCheck, AiUsageOutcome::Released);
            }

            return $done;
        });
    }

    /**
     * Rules 3a / 3b, the reaper's: the intake lease ran out. The first time
     * the upload is checked once more; the second time it is unusable input.
     */
    public function intakeLeaseLost(string $id): bool
    {
        return $this->atomically(function () use ($id): bool {
            $overdue = 'lease_expires_at IS NOT NULL AND lease_expires_at < :now';

            $tries = $this->apply(
                $id,
                AiScanEvent::IntakeLeaseLost,
                ['lease_expires_at = NULL', 'claimed_at = NULL', 'stage = NULL', 'stage_started_at = NULL', 'intake_losses = intake_losses + 1'],
                ['max' => AiScanLimits::MAX_INTAKE_TRIES],
                $overdue . ' AND intake_losses + 1 < :max',
                returning: 'intake_tries',
                code: 'restarted',
            );

            if ($tries !== null) {
                $this->usage->close($id, $tries, AiUsageStep::PreCheck, AiUsageOutcome::LeaseLost);

                return true;
            }

            $row = $this->applyReturning(
                $id,
                AiScanEvent::IntakeLeaseLostAgain,
                [...$this->ended(AiScanStopReason::UnusableInput->value), 'lease_expires_at = NULL', 'claimed_at = NULL', 'intake_losses = intake_losses + 1', 'failure_count = failure_count + 1'],
                ['max' => AiScanLimits::MAX_INTAKE_TRIES],
                $overdue . ' AND intake_losses + 1 >= :max',
                'try, intake_tries',
                AiScanStopReason::UnusableInput->value,
            );

            if ($row === null) {
                return false;
            }

            $this->credits->refund($id, self::int($row['try']));
            $this->usage->close($id, self::int($row['intake_tries']), AiUsageStep::PreCheck, AiUsageOutcome::LeaseLost, AiScanStopReason::UnusableInput->value);

            return true;
        });
    }

    // ---- build lane --------------------------------------------------------

    /**
     * Rule 4: a builder takes a `queued` scan. Returns the new `run`.
     */
    public function claimBuild(string $id, string $leaseTokenHash, null|string $scannerVersion, null|string $definitionVersion): null|int
    {
        return $this->atomically(function () use ($id, $leaseTokenHash, $scannerVersion, $definitionVersion): null|int {
            $run = $this->apply(
                $id,
                AiScanEvent::BuildClaim,
                [
                    'run = run + 1',
                    'lease_token_hash = :hash',
                    'lease_expires_at = :lease',
                    'claimed_at = :now',
                    'waiting_since = NULL',
                    'beat_seq = 0',
                    "stage = 'read'",
                    'stage_started_at = :now',
                    'note = NULL',
                    'note_params = NULL',
                    'round = 0',
                    "candidates = '[]'",
                    'kept_candidate = NULL',
                    'scanner_version = COALESCE(:scanner, scanner_version)',
                    'definition_version = COALESCE(:definition, definition_version)',
                ],
                ['hash' => $leaseTokenHash, 'lease' => $this->in(AiScanLimits::LEASE_SECONDS), 'scanner' => $scannerVersion, 'definition' => $definitionVersion],
                'lease_expires_at IS NULL',
                returning: 'run',
            );

            if ($run === null) {
                return null;
            }

            $this->usage->open($id, $run, AiUsageStep::Builder, $leaseTokenHash);

            return $run;
        });
    }

    /**
     * Rule 5: the builder delivered a proposal. Accepted from the run that
     * holds the scan — and from a run the reaper already put back to `queued`
     * while nobody has claimed it again (its work is not thrown away).
     *
     * @param array<string, mixed> $proposal
     * @param array<string, mixed> $report
     */
    public function finishProposed(string $id, int $run, string $leaseTokenHash, int $keptCandidate, array $proposal, array $report, bool $unfinished): bool
    {
        return $this->atomically(function () use ($id, $run, $leaseTokenHash, $keptCandidate, $proposal, $report, $unfinished): bool {
            $try = $this->apply(
                $id,
                AiScanEvent::FinishProposed,
                [
                    ...$this->ended(null),
                    'kept_candidate = :kept',
                    'proposal = CAST(:proposal AS JSONB)',
                    'report = CAST(:report AS JSONB)',
                    'unfinished = :unfinished',
                    "stage = 'finish'",
                ],
                ['kept' => $keptCandidate, 'proposal' => self::json($proposal), 'report' => self::json($report), 'unfinished' => $unfinished ? 'true' : 'false', 'run' => $run, 'hash' => $leaseTokenHash],
                self::RUN_OWNS_THE_SCAN,
            );

            if ($try === null) {
                return false;
            }

            $this->credits->settle($id, $try);
            $this->usage->close($id, $run, AiUsageStep::Builder, AiUsageOutcome::Proposed);
            $this->runtime->scanEnded(null);

            return true;
        });
    }

    /** Rule 6: the builder gave up for a system reason. */
    public function finishFailed(string $id, int $run, string $leaseTokenHash, AiScanErrorCode $error): bool
    {
        return $this->atomically(function () use ($id, $run, $leaseTokenHash, $error): bool {
            $set = $this->ended($error->value);

            if ($error->countsAgainstSource()) {
                $set[] = 'failure_count = failure_count + 1';
            }

            $try = $this->apply($id, AiScanEvent::FinishFailed, $set, ['run' => $run, 'hash' => $leaseTokenHash], self::RUN_OWNS_THE_SCAN, code: $error->value);

            if ($try === null) {
                return false;
            }

            $this->credits->refund($id, $try);
            $this->usage->close($id, $run, AiUsageStep::Builder, AiUsageOutcome::Failed, $error->value);
            $this->runtime->scanEnded($error->value);

            return true;
        });
    }

    /** Rule 6: the provider refused the content. */
    public function finishRefused(string $id, int $run, string $leaseTokenHash): bool
    {
        return $this->atomically(function () use ($id, $run, $leaseTokenHash): bool {
            $try = $this->apply($id, AiScanEvent::FinishRefused, $this->ended(null), ['run' => $run, 'hash' => $leaseTokenHash], self::RUN_OWNS_THE_SCAN);

            if ($try === null) {
                return false;
            }

            $this->credits->refund($id, $try);
            $this->usage->close($id, $run, AiUsageStep::Builder, AiUsageOutcome::Refused);
            $this->runtime->scanEnded(null);

            return true;
        });
    }

    /**
     * Rule 7: the scanner hands the run back (shutdown, or the provider is
     * blocked). Back to `queued`; the automatic restart is NOT consumed.
     */
    public function releaseBuild(string $id, int $run, string $leaseTokenHash): bool
    {
        return $this->atomically(function () use ($id, $run, $leaseTokenHash): bool {
            $done = $this->apply(
                $id,
                AiScanEvent::BuildRelease,
                [...$this->requeued(), 'lease_token_hash = NULL'],
                ['run' => $run, 'hash' => $leaseTokenHash],
                'run = :run AND lease_token_hash = :hash',
                code: 'restarted',
            ) !== null;

            if ($done) {
                $this->usage->close($id, $run, AiUsageStep::Builder, AiUsageOutcome::Released);
            }

            return $done;
        });
    }

    /**
     * Rules 7 / 6, the reaper's: the build lease ran out. Once per try the
     * run is put back to `queued`; a second loss fails the scan as
     * interrupted, with a refund.
     */
    public function buildLeaseLost(string $id): bool
    {
        return $this->atomically(function () use ($id): bool {
            $overdue = 'lease_expires_at IS NOT NULL AND lease_expires_at < :now';

            $run = $this->apply(
                $id,
                AiScanEvent::BuildLeaseLost,
                [...$this->requeued(), 'auto_restart = 1'],
                [],
                $overdue . ' AND auto_restart = 0',
                returning: 'run',
                code: 'restarted',
            );

            if ($run !== null) {
                $this->usage->close($id, $run, AiUsageStep::Builder, AiUsageOutcome::LeaseLost);

                return true;
            }

            $row = $this->applyReturning(
                $id,
                AiScanEvent::BuildLeaseLostAgain,
                // A run that died twice counts against the picture (§5.4).
                [...$this->ended(AiScanErrorCode::Interrupted->value), 'lease_expires_at = NULL', 'claimed_at = NULL', 'failure_count = failure_count + 1'],
                [],
                $overdue . ' AND auto_restart >= 1',
                'try, run',
                AiScanErrorCode::Interrupted->value,
            );

            if ($row === null) {
                return false;
            }

            $this->credits->refund($id, self::int($row['try']));
            $this->usage->close($id, self::int($row['run']), AiUsageStep::Builder, AiUsageOutcome::LeaseLost, AiScanErrorCode::Interrupted->value);
            $this->runtime->scanEnded(AiScanErrorCode::Interrupted->value);

            return true;
        });
    }

    // ---- user --------------------------------------------------------------

    /**
     * Rules 8 / 9. A scan still waiting is refunded; a running one is settled
     * — the work was already paid for. One statement decides which, so a
     * claim racing the cancel cannot turn a counted cancel into a free one.
     */
    public function cancel(string $id): bool
    {
        return $this->atomically(function () use ($id): bool {
            $row = $this->applyReturning(
                $id,
                AiScanEvent::Cancel,
                [...$this->ended(null), 'lease_expires_at = NULL', 'claimed_at = NULL'],
                [],
                '',
                'try',
            );

            if ($row === null) {
                return false;
            }

            $wasRunning = $row['_from'] === AiScanStatus::Running->value;
            $this->credits->close($id, self::int($row['try']), $wasRunning ? AiLedgerReason::Settle : AiLedgerReason::Refund);
            $this->usage->closeOpen($id, AiUsageOutcome::Cancelled);

            return true;
        });
    }

    /** Rule 10: the project's entitlement was switched off — waiting scans go, refunded. */
    public function cancelForEntitlementOff(string $id): bool
    {
        return $this->endWithRefund($id, AiScanEvent::EntitlementOff, null, AiUsageOutcome::Cancelled, 'not_enabled');
    }

    /** Rule 11, the reaper's: waited longer than Q. */
    public function queueTimeout(string $id): bool
    {
        return $this->atomically(function () use ($id): bool {
            $try = $this->apply(
                $id,
                AiScanEvent::QueueTimeout,
                [...$this->ended(AiScanErrorCode::QueueTimeout->value), 'lease_expires_at = NULL', 'claimed_at = NULL'],
                ['since' => $this->in(-AiScanLimits::QUEUE_TIMEOUT_SECONDS)],
                // A scan the intake is working on right now is left to it.
                'waiting_since IS NOT NULL AND waiting_since < :since AND (lease_expires_at IS NULL OR lease_expires_at < :now)',
                code: AiScanErrorCode::QueueTimeout->value,
            );

            if ($try === null) {
                return false;
            }

            $this->credits->refund($id, $try);
            $this->usage->closeOpen($id, AiUsageOutcome::Failed, AiScanErrorCode::QueueTimeout->value);

            return true;
        });
    }

    /** Rule 13a: an admin set the mode to `off`. */
    public function interruptForModeOff(string $id): bool
    {
        return $this->endWithRefund($id, AiScanEvent::ModeOff, AiScanErrorCode::Interrupted->value, AiUsageOutcome::Interrupted, AiScanErrorCode::Interrupted->value);
    }

    /**
     * Rule 12, "Zkusit přesto": only after a photo-of-a-print stop, once per
     * upload. The pre-check is not repeated — the working copy exists.
     * The caller has checked the start guards and reserves the credit.
     */
    public function continueAnyway(string $id, null|string $userId): bool
    {
        return $this->restart($id, AiScanEvent::ContinueAnyway, $userId, 'approximate = false AND error_code = :reason', ['reason' => AiScanStopReason::PhotoOfPrint->value], ['approximate = true']);
    }

    /**
     * Rule 13, "Zkusit znovu": at most two per upload. Back to `queued` when
     * the intake had finished (a pre-check is stored, the working copy
     * exists); back to `checking` when it never did — the scan failed while
     * still `checking` (rule 3c, a queue timeout, mode `off`).
     */
    public function retry(string $id, null|string $userId): bool
    {
        $retries = '(try - 1 - CASE WHEN approximate THEN 1 ELSE 0 END) < :max';
        $params = ['max' => AiScanLimits::MAX_RETRIES];

        return $this->restart($id, AiScanEvent::Retry, $userId, $retries . ' AND pre_check IS NOT NULL', $params, [])
            || $this->restart($id, AiScanEvent::RetryCheck, $userId, $retries . ' AND pre_check IS NULL', $params, ['intake_losses = 0']);
    }

    /**
     * Rule 14. The caller (`AcceptTemplateScan` / `AcceptCalendarScan`, P3 /
     * P5) runs it inside its own transaction; a repeat returns false and the
     * caller answers with the ids already stored.
     */
    public function accept(string $id, null|string $templateId, null|string $variantId): bool
    {
        return $this->atomically(fn (): bool => $this->apply(
            $id,
            AiScanEvent::Accept,
            // `expires_at` stays: one retention date per scan, accepting does not move it.
            ['result_template_id = :template', 'result_variant_id = :variant', 'seen_at = COALESCE(seen_at, :now)'],
            ['template' => $templateId, 'variant' => $variantId],
        ) !== null);
    }

    /**
     * Rule 14 for calendars: the scanned layout was applied. `restore` keeps
     * what the calendar held before — its layout, layout choice, switches and
     * marker — and a hash of what the accept wrote, until `expires_at`.
     *
     * @param array<string, mixed> $restore
     */
    public function acceptCalendar(string $id, array $restore): bool
    {
        return $this->atomically(fn (): bool => $this->apply(
            $id,
            AiScanEvent::Accept,
            ['restore = CAST(:restore AS JSONB)', 'seen_at = COALESCE(seen_at, :now)'],
            ['restore' => self::json($restore)],
            "kind = 'calendar'",
        ) !== null);
    }

    /**
     * "Vrátit původní rozvržení" was used: the snapshot stays (the date of
     * the scan decides when it goes), the row remembers it was taken.
     */
    public function markRestored(string $id): bool
    {
        return $this->connection->executeStatement(
            "UPDATE ai_scan SET restore = jsonb_set(restore, '{restoredAt}', to_jsonb(CAST(:now AS TEXT)))
              WHERE id = :id AND status = 'accepted' AND restore IS NOT NULL AND NOT jsonb_exists(restore, 'restoredAt')",
            ['id' => $id, 'now' => $this->clock->now()->format(DATE_ATOM)],
        ) === 1;
    }

    /** Rule 15. No ledger line: a delivered scan stays counted. */
    public function discard(string $id): bool
    {
        return $this->atomically(fn (): bool => $this->apply($id, AiScanEvent::Discard, ['seen_at = COALESCE(seen_at, :now)']) !== null);
    }

    /**
     * Rule 16, the purge's: the retention ran out. The proposal and its
     * snapshot go from the row here; the caller deletes the files after.
     */
    public function expire(string $id): bool
    {
        return $this->atomically(fn (): bool => $this->apply(
            $id,
            AiScanEvent::Expire,
            ['proposal = NULL', 'restore = NULL', 'pictures = NULL', 'fixes = NULL', "candidates = '[]'", 'expires_at = NULL'],
            [],
            'expires_at IS NOT NULL AND expires_at < :now',
        ) !== null);
    }

    // ---- internals ---------------------------------------------------------

    private function endIntake(string $id, int $run, string $leaseTokenHash, AiScanEvent $event, null|string $errorCode, AiUsageOutcome $outcome): bool
    {
        return $this->atomically(function () use ($id, $run, $leaseTokenHash, $event, $errorCode, $outcome): bool {
            $try = $this->apply(
                $id,
                $event,
                [...$this->ended($errorCode), 'lease_expires_at = NULL', 'claimed_at = NULL'],
                ['run' => $run, 'hash' => $leaseTokenHash],
                'intake_tries = :run AND lease_token_hash = :hash AND lease_expires_at IS NOT NULL',
                code: $errorCode,
            );

            if ($try === null) {
                return false;
            }

            $this->credits->refund($id, $try);
            $this->usage->close($id, $run, AiUsageStep::PreCheck, $outcome, $errorCode);

            return true;
        });
    }

    /** `finish` comes from the run holding the scan, or from the last run of a scan nobody has claimed since. */
    private const string RUN_OWNS_THE_SCAN = "run = :run AND lease_token_hash = :hash AND (status = 'running' OR lease_expires_at IS NULL)";

    /**
     * @param array<string, mixed> $params
     * @param list<string> $extraSet
     */
    private function restart(string $id, AiScanEvent $event, null|string $userId, string $guard, array $params, array $extraSet): bool
    {
        return $this->atomically(function () use ($id, $event, $userId, $guard, $params, $extraSet): bool {
            $row = $this->applyReturning(
                $id,
                $event,
                [
                    ...$extraSet,
                    'try = try + 1',
                    'auto_restart = 0',
                    'started_at = :now',
                    'waiting_since = :now',
                    'finished_at = NULL',
                    'expires_at = NULL',
                    'error_code = NULL',
                    'lease_expires_at = NULL',
                    'claimed_at = NULL',
                    // A new try: the previous run's token must not finish it.
                    'lease_token_hash = NULL',
                    'stage = NULL',
                    'stage_started_at = NULL',
                    'note = NULL',
                    'note_params = NULL',
                    'seen_at = NULL',
                    'notified_at = NULL',
                ],
                $params,
                $guard,
                'try, project_id',
            );

            if ($row === null) {
                return false;
            }

            $this->credits->reserve(self::string($row['project_id']), $id, self::int($row['try']), $userId);

            return true;
        });
    }

    private function endWithRefund(string $id, AiScanEvent $event, null|string $errorCode, AiUsageOutcome $outcome, string $code): bool
    {
        return $this->atomically(function () use ($id, $event, $errorCode, $outcome, $code): bool {
            $try = $this->apply($id, $event, [...$this->ended($errorCode), 'lease_expires_at = NULL', 'claimed_at = NULL'], [], '', code: $code);

            if ($try === null) {
                return false;
            }

            $this->credits->refund($id, $try);
            $this->usage->closeOpen($id, $outcome, $errorCode);

            return true;
        });
    }

    /**
     * The columns every ending writes. The lease's expiry is left alone on
     * purpose where the caller does not clear it: a `finish` clears nothing
     * the late-call checks read.
     *
     * @return list<string>
     */
    private function ended(null|string $errorCode): array
    {
        return [
            'finished_at = :now',
            sprintf("expires_at = CAST(:now AS TIMESTAMP) + INTERVAL '%d days'", AiScanLimits::RETENTION_DAYS),
            'waiting_since = NULL',
            $errorCode === null ? 'error_code = NULL' : sprintf("error_code = '%s'", preg_replace('/[^a-z_]/', '', $errorCode)),
            'note = NULL',
            'note_params = NULL',
        ];
    }

    /**
     * Back to `queued`. The token hash stays for the reaper's requeue — that
     * run's late `finish` is still welcome — and is cleared by the callers
     * that end the run for good.
     *
     * @return list<string>
     */
    private function requeued(): array
    {
        return ['lease_expires_at = NULL', 'claimed_at = NULL', 'waiting_since = :now', 'stage = NULL', 'stage_started_at = NULL', 'note = NULL', 'note_params = NULL'];
    }

    /**
     * Runs the conditional update of `$event` and returns one integer column
     * of the changed row (`try` unless told otherwise) — or null when no row
     * changed, i.e. the transition did not happen.
     *
     * @param list<string> $set
     * @param array<string, mixed> $params
     */
    private function apply(
        string $id,
        AiScanEvent $event,
        array $set = [],
        array $params = [],
        string $guard = '',
        string $returning = 'try',
        null|string $code = null,
        bool $history = true,
    ): null|int {
        $row = $this->applyReturning($id, $event, $set, $params, $guard, $returning, $code, $history);

        return $row === null ? null : self::int($row[$returning] ?? null);
    }

    /**
     * @param list<string> $set
     * @param array<string, mixed> $params
     * @return null|array<string, mixed>
     */
    private function applyReturning(
        string $id,
        AiScanEvent $event,
        array $set,
        array $params,
        string $guard,
        string $returning,
        null|string $code = null,
        bool $history = true,
    ): null|array {
        $now = AiScanTime::sql($this->clock->now());

        // One statement per source state: the target and the history line
        // depend on where the row is, and only one of them can match.
        foreach (AiScanTransitionTable::sources($event) as $from) {
            $to = AiScanTransitionTable::target($from, $event);
            assert($to !== null);

            $assignments = [sprintf("status = '%s'", $to->value), ...$set];

            if ($history) {
                $assignments[] = sprintf(
                    'events = (CASE WHEN jsonb_array_length(events) >= %d THEN events - 0 ELSE events END) || CAST(:event AS JSONB)',
                    AiScanLimits::EVENTS_MAX,
                );
            }

            $sql = sprintf(
                "UPDATE ai_scan SET %s WHERE id = :id AND status = '%s'%s RETURNING %s",
                implode(', ', $assignments),
                $from->value,
                $guard === '' ? '' : ' AND ' . $guard,
                $returning,
            );

            $bound = ['id' => $id, ...$params];

            if (str_contains($sql, ':now')) {
                $bound['now'] = $now;
            }

            if ($history) {
                $bound['event'] = self::json([array_filter([
                    'at' => $now,
                    'event' => $event->value,
                    'from' => $from->value,
                    'to' => $to->value,
                    'code' => $code,
                ], static fn (mixed $value): bool => $value !== null)]);
            }

            // Bind exactly what the statement names — the driver refuses extras.
            $bound = array_filter(
                $bound,
                static fn (string $name): bool => preg_match('/:' . $name . '\\b/', $sql) === 1,
                ARRAY_FILTER_USE_KEY,
            );

            $row = $this->connection->fetchAssociative($sql, $bound);

            if ($row !== false) {
                $row['_from'] = $from->value;

                // A scan that ended tells its requester — once, delayed, and
                // only if they have not seen it by then (ux.md §7).
                if ($to !== $from && in_array($to, [AiScanStatus::Ready, AiScanStatus::Stopped, AiScanStatus::Failed, AiScanStatus::Refused], true)) {
                    $this->notifications->schedule($id);
                }

                return $row;
            }
        }

        return null;
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function atomically(\Closure $work): mixed
    {
        return $this->connection->transactional($work);
    }

    private function in(int $seconds): string
    {
        return AiScanTime::sql($this->clock->now()->modify(sprintf('%+d seconds', $seconds)));
    }

    private static function json(mixed $value): null|string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
