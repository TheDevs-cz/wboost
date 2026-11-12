<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Value\AiScan\AiLedgerReason;

/**
 * The credit ledger (`ai_credit_ledger`): a start reserves one scan of the
 * project's month, the end of that try settles it (it counts) or refunds it.
 *
 * Idempotency lives in the inserts, not in the callers: a second reserve,
 * settle or refund of the same `(scan, try)` inserts nothing, and a settle
 * can never follow a refund or the other way round.
 */
final readonly class AiScanCredits
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function reserve(string $projectId, string $scanId, int $try, null|string $userId): void
    {
        $now = $this->clock->now();

        $this->connection->executeStatement(
            'INSERT INTO ai_credit_ledger (id, project_id, scan_id, try, period, delta, reason, cost_micro_usd, created_by, created_at)
             VALUES (:id, :project, :scan, :try, :period, -1, :reason, 0, :user, :now)
             ON CONFLICT (scan_id, try, reason) DO NOTHING',
            [
                'id' => Uuid::uuid7()->toString(),
                'project' => $projectId,
                'scan' => $scanId,
                'try' => $try,
                'period' => AiScanTime::period($now),
                'reason' => AiLedgerReason::Reserve->value,
                'user' => $userId,
                'now' => AiScanTime::sql($now),
            ],
        );
    }

    public function settle(string $scanId, int $try): bool
    {
        return $this->close($scanId, $try, AiLedgerReason::Settle);
    }

    public function refund(string $scanId, int $try): bool
    {
        return $this->close($scanId, $try, AiLedgerReason::Refund);
    }

    /**
     * Closes the reserve of `(scan, try)` exactly once: the row is inserted
     * only when the reserve exists and neither a settle nor a refund does.
     * The period is the reserve's, so a refund made after midnight of the
     * 1st still gives back to the month that was charged.
     *
     * @return bool whether THIS call closed it
     */
    public function close(string $scanId, int $try, AiLedgerReason $reason): bool
    {
        assert($reason === AiLedgerReason::Settle || $reason === AiLedgerReason::Refund);

        return $this->connection->executeStatement(
            'INSERT INTO ai_credit_ledger (id, project_id, scan_id, try, period, delta, reason, cost_micro_usd, created_by, created_at)
             SELECT :id, r.project_id, r.scan_id, r.try, r.period, :delta, :reason,
                    COALESCE((SELECT cost_micro_usd FROM ai_scan WHERE id = r.scan_id), 0), r.created_by, :now
               FROM ai_credit_ledger r
              WHERE r.scan_id = :scan AND r.try = :try AND r.reason = \'reserve\'
                AND NOT EXISTS (
                    SELECT 1 FROM ai_credit_ledger c
                     WHERE c.scan_id = r.scan_id AND c.try = r.try AND c.reason IN (\'settle\', \'refund\')
                )
                 ON CONFLICT (scan_id, try, reason) DO NOTHING',
            [
                'id' => Uuid::uuid7()->toString(),
                'delta' => $reason === AiLedgerReason::Refund ? 1 : 0,
                'reason' => $reason->value,
                'now' => AiScanTime::sql($this->clock->now()),
                'scan' => $scanId,
                'try' => $try,
            ],
        ) === 1;
    }

    /**
     * Scans counted against the project's limit in the month of `$at`:
     * reserves minus refunds whose period is that month (grants and
     * adjustments move it too).
     */
    /** Whether this try of the scan was counted (its reserve was settled, not refunded). */
    public function wasSettled(string $scanId, int $try): bool
    {
        return $this->connection->fetchOne(
            "SELECT 1 FROM ai_credit_ledger WHERE scan_id = :scan AND try = :try AND reason = 'settle'",
            ['scan' => $scanId, 'try' => $try],
        ) !== false;
    }

    public function usedInMonth(string $projectId, DateTimeImmutable $at): int
    {
        $sum = $this->connection->fetchOne(
            'SELECT COALESCE(SUM(delta), 0) FROM ai_credit_ledger WHERE project_id = :project AND period = :period',
            ['project' => $projectId, 'period' => AiScanTime::period($at)],
        );

        return max(0, -(is_numeric($sum) ? (int) $sum : 0));
    }

    /** Scans of the project that ever counted (settled). */
    public function countedTotal(string $projectId): int
    {
        $count = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ai_credit_ledger WHERE project_id = :project AND reason = 'settle'",
            ['project' => $projectId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /** Starts (reserves) of this user today, Europe/Prague — refunds do not give a start back. */
    public function startsTodayByUser(string $userId, DateTimeImmutable $at): int
    {
        return $this->startsSince('created_by', $userId, $at);
    }

    public function startsTodayInProject(string $projectId, DateTimeImmutable $at): int
    {
        return $this->startsSince('project_id', $projectId, $at);
    }

    private function startsSince(string $column, string $id, DateTimeImmutable $at): int
    {
        $count = $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM ai_credit_ledger WHERE %s = :id AND reason = \'reserve\' AND created_at >= :since', $column),
            ['id' => $id, 'since' => AiScanTime::sql(AiScanTime::startOfDay($at))],
        );

        return is_numeric($count) ? (int) $count : 0;
    }
}
