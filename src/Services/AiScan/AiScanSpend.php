<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * The money limits — separate from credits. Both ceilings count **recorded
 * spend of the day (Europe/Prague) plus the reserved budget of every running
 * run**, from the database: a run that is working has not reported its cost
 * yet, and without the reservation several runs could start just under the
 * ceiling and all overshoot it.
 */
final readonly class AiScanSpend
{
    public function __construct(
        private Connection $connection,
        private AiScanDefinition $definition,
    ) {
    }

    /** Recorded + in-flight spend of the whole system today, in micro-USD. */
    public function totalToday(DateTimeImmutable $now): int
    {
        return $this->spent(null, $now) + $this->running(null) * $this->definition->engineBudgetMicroUsd();
    }

    public function projectToday(string $projectId, DateTimeImmutable $now): int
    {
        return $this->spent($projectId, $now) + $this->running($projectId) * $this->definition->engineBudgetMicroUsd();
    }

    /** What is reserved for runs in flight (shown by the status command). */
    public function reservedNow(): int
    {
        return $this->running(null) * $this->definition->engineBudgetMicroUsd();
    }

    /** Recorded spend of the whole system today, without what running runs reserve — it only grows within a day. */
    public function recordedToday(DateTimeImmutable $now): int
    {
        return $this->spent(null, $now);
    }

    /** Recorded spend since the first of the month (Europe/Prague). */
    public function recordedThisMonth(DateTimeImmutable $now): int
    {
        $sum = $this->connection->fetchOne(
            'SELECT COALESCE(SUM(cost_micro_usd), 0) FROM ai_usage_event WHERE occurred_at >= :since',
            ['since' => AiScanTime::sql(AiScanTime::startOfDay($now)->modify('first day of this month'))],
        );

        return is_numeric($sum) ? (int) $sum : 0;
    }

    /** One run's reserved budget, in micro-USD. */
    public function runBudget(): int
    {
        return $this->definition->engineBudgetMicroUsd();
    }

    /**
     * No further run fits under the daily ceiling, whatever is running: what
     * is RECORDED today plus one run's budget is over it. Unlike
     * {@see totalCeilingReached()} it does not flap with reservations.
     */
    public function dayExhausted(AiScanRuntimeSnapshot $runtime, DateTimeImmutable $now): bool
    {
        return $this->spent(null, $now) + $this->definition->engineBudgetMicroUsd() > $runtime->dailyCeilingMicroUsd;
    }

    public function totalCeilingReached(AiScanRuntimeSnapshot $runtime, DateTimeImmutable $now): bool
    {
        return $this->totalToday($now) >= $runtime->dailyCeilingMicroUsd;
    }

    public function projectCeilingReached(AiScanRuntimeSnapshot $runtime, string $projectId, DateTimeImmutable $now): bool
    {
        return $this->projectToday($projectId, $now) >= $runtime->projectDailyMicroUsd;
    }

    /**
     * Whether one MORE run may start: what is spent and reserved, plus this
     * run's own budget, must fit under both ceilings.
     */
    public function allowsAnotherRun(AiScanRuntimeSnapshot $runtime, string $projectId, DateTimeImmutable $now): bool
    {
        $budget = $this->definition->engineBudgetMicroUsd();

        return $this->totalToday($now) + $budget <= $runtime->dailyCeilingMicroUsd
            && $this->projectToday($projectId, $now) + $budget <= $runtime->projectDailyMicroUsd;
    }

    private function spent(null|string $projectId, DateTimeImmutable $now): int
    {
        $sql = 'SELECT COALESCE(SUM(cost_micro_usd), 0) FROM ai_usage_event WHERE occurred_at >= :since';
        $params = ['since' => AiScanTime::sql(AiScanTime::startOfDay($now))];

        if ($projectId !== null) {
            $sql .= ' AND project_id = :project';
            $params['project'] = $projectId;
        }

        $sum = $this->connection->fetchOne($sql, $params);

        return is_numeric($sum) ? (int) $sum : 0;
    }

    private function running(null|string $projectId): int
    {
        $sql = "SELECT COUNT(*) FROM ai_scan WHERE status = 'running'";
        $params = [];

        if ($projectId !== null) {
            $sql .= ' AND project_id = :project';
            $params['project'] = $projectId;
        }

        $count = $this->connection->fetchOne($sql, $params);

        return is_numeric($count) ? (int) $count : 0;
    }
}
