<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\AiScan\AiUsageFigures;
use WBoost\Web\Value\AiScan\AiUsageOutcome;
use WBoost\Web\Value\AiScan\AiUsageStep;

/**
 * Writes `ai_usage_event`: one row per `(scan, run, step)`.
 *
 * - {@see open()} when a lane claims the scan, so a run that dies before its
 *   first heartbeat still has a row;
 * - {@see record()} from every heartbeat and from finish / release — the
 *   figures are cumulative, so the upsert is idempotent and a lost heartbeat
 *   costs nothing;
 * - {@see close()} when the run ends, however it ends. A closed row still
 *   accepts later figures (a late call "records usage only"), never a second
 *   outcome.
 *
 * The cost of a row is the price table's when it knows the model, otherwise
 * the engine's own figure — and never more than {@see costCapMicroUsd()}.
 *
 * The scan row's totals are the sums of its usage rows, refreshed here.
 */
final readonly class RecordAiUsage
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private AiScanDefinition $definition,
    ) {
    }

    /**
     * `$leaseTokenHash`: the claim's lease token (hashed), kept on the row so
     * that this run can still report what it spent after the scan was given
     * to another run ({@see staleRun()}).
     */
    public function open(string $scanId, int $run, AiUsageStep $step, null|string $leaseTokenHash = null): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ai_usage_event
                    (id, scan_id, run, step, occurred_at, project_id, project_name, owner_id, owner_email, user_id, user_email, channel, kind, lease_token_hash)
             SELECT :id, s.id, :run, :step, :now, p.id, p.name, o.id, o.email, s.requested_by_id, s.requester_email, s.channel, s.kind, :hash
               FROM ai_scan s
               JOIN project p ON p.id = s.project_id
               JOIN "user" o ON o.id = p.owner_id
              WHERE s.id = :scan
                 ON CONFLICT (scan_id, run, step) DO NOTHING',
            [
                'id' => Uuid::uuid7()->toString(),
                'scan' => $scanId,
                'run' => $run,
                'step' => $step->value,
                'now' => AiScanTime::sql($this->clock->now()),
                'hash' => $leaseTokenHash,
            ],
        );
    }

    /**
     * The usage row a lease token opened — for a call whose run no longer
     * holds the scan (the reaper put it back and somebody claimed it again, it
     * was released, retried, or the scan row is gone). Null when the token
     * opened no row of this scan.
     *
     * @return null|array{run: int, step: AiUsageStep}
     */
    public function staleRun(string $scanId, string $leaseTokenHash): null|array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT run, step FROM ai_usage_event WHERE scan_id = :scan AND lease_token_hash = :hash ORDER BY occurred_at DESC LIMIT 1',
            ['scan' => $scanId, 'hash' => $leaseTokenHash],
        );

        if ($row === false) {
            return null;
        }

        $step = AiUsageStep::tryFrom(is_string($row['step']) ? $row['step'] : '');

        return $step === null || !is_numeric($row['run']) ? null : ['run' => (int) $row['run'], 'step' => $step];
    }

    public function record(string $scanId, int $run, AiUsageStep $step, AiUsageFigures $figures): void
    {
        $this->open($scanId, $run, $step);

        $priced = $this->definition->costMicroUsd(
            $figures->model,
            $figures->inputTokens,
            $figures->outputTokens,
            $figures->cacheReadTokens,
            $figures->cacheWriteTokens,
            $figures->cacheWrite1hTokens,
        );

        // One run cannot have cost more than a few budgets, whatever it
        // says (AiScanLimits::USAGE_COST_CAP_BUDGETS); a row AT the cap is
        // what the admins are told about (`usage_suspect`).
        $cost = min($priced ?? $figures->engineCostMicroUsd, $this->costCapMicroUsd());

        // GREATEST: the figures are cumulative, so an out-of-order heartbeat
        // must never take anything back.
        $this->connection->executeStatement(
            'UPDATE ai_usage_event
                SET provider = :provider,
                    model = CASE WHEN :model = \'\' THEN model ELSE :model END,
                    input_tokens = GREATEST(input_tokens, :input),
                    output_tokens = GREATEST(output_tokens, :output),
                    cache_read_tokens = GREATEST(cache_read_tokens, :cacheRead),
                    cache_write_tokens = GREATEST(cache_write_tokens, :cacheWrite),
                    cache_write_1h_tokens = GREATEST(cache_write_1h_tokens, :cacheWrite1h),
                    engine_cost_micro_usd = GREATEST(engine_cost_micro_usd, :engineCost),
                    cost_micro_usd = GREATEST(cost_micro_usd, :cost),
                    price_version = :priceVersion,
                    turns = GREATEST(turns, :turns),
                    duration_ms = GREATEST(duration_ms, :duration)
              WHERE scan_id = :scan AND run = :run AND step = :step',
            [
                'provider' => $figures->provider,
                'model' => $figures->model,
                'input' => $figures->inputTokens,
                'output' => $figures->outputTokens,
                'cacheRead' => $figures->cacheReadTokens,
                'cacheWrite' => $figures->cacheWriteTokens,
                'cacheWrite1h' => $figures->cacheWrite1hTokens,
                'engineCost' => $figures->engineCostMicroUsd,
                'cost' => $cost,
                'priceVersion' => $priced === null ? null : $this->definition->priceVersion(),
                'turns' => $figures->turns,
                'duration' => $figures->durationMs,
                'scan' => $scanId,
                'run' => $run,
                'step' => $step->value,
            ],
        );

        $this->refreshTotals($scanId);
    }

    /** The most one usage row is recorded to have cost. */
    public function costCapMicroUsd(): int
    {
        return AiScanLimits::USAGE_COST_CAP_BUDGETS * $this->definition->engineBudgetMicroUsd();
    }

    /**
     * Closes the row once; a second close changes nothing.
     */
    public function close(string $scanId, int $run, AiUsageStep $step, AiUsageOutcome $outcome, null|string $errorCode = null): void
    {
        $this->connection->executeStatement(
            'UPDATE ai_usage_event
                SET closed_at = :now, outcome = :outcome, error_code = :error
              WHERE scan_id = :scan AND run = :run AND step = :step AND closed_at IS NULL',
            [
                'now' => AiScanTime::sql($this->clock->now()),
                'outcome' => $outcome->value,
                'error' => $errorCode,
                'scan' => $scanId,
                'run' => $run,
                'step' => $step->value,
            ],
        );
    }

    /**
     * Closes whatever is still open for a scan — the row of the lane that
     * held it when it was cancelled, interrupted or purged.
     */
    public function closeOpen(string $scanId, AiUsageOutcome $outcome, null|string $errorCode = null): void
    {
        $this->connection->executeStatement(
            'UPDATE ai_usage_event SET closed_at = :now, outcome = :outcome, error_code = :error WHERE scan_id = :scan AND closed_at IS NULL',
            [
                'now' => AiScanTime::sql($this->clock->now()),
                'outcome' => $outcome->value,
                'error' => $errorCode,
                'scan' => $scanId,
            ],
        );
    }

    /**
     * Rows left open by scans that are no longer in progress (or are gone) —
     * the nightly purge's safety net. Returns how many it closed.
     */
    public function closeOrphans(): int
    {
        return (int) $this->connection->executeStatement(
            "UPDATE ai_usage_event u
                SET closed_at = :now, outcome = :outcome
              WHERE u.closed_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM ai_scan s
                     WHERE s.id = u.scan_id AND s.status IN ('checking', 'queued', 'running')
                )",
            ['now' => AiScanTime::sql($this->clock->now()), 'outcome' => AiUsageOutcome::Purged->value],
        );
    }

    private function refreshTotals(string $scanId): void
    {
        $this->connection->executeStatement(
            'UPDATE ai_scan s
                SET input_tokens = t.input_tokens, output_tokens = t.output_tokens,
                    cache_read_tokens = t.cache_read_tokens, cache_write_tokens = t.cache_write_tokens,
                    cost_micro_usd = t.cost_micro_usd, turns = t.turns, duration_ms = t.duration_ms,
                    model = COALESCE(t.model, s.model)
               FROM (
                    SELECT COALESCE(SUM(input_tokens), 0) AS input_tokens, COALESCE(SUM(output_tokens), 0) AS output_tokens,
                           COALESCE(SUM(cache_read_tokens), 0) AS cache_read_tokens, COALESCE(SUM(cache_write_tokens), 0) AS cache_write_tokens,
                           COALESCE(SUM(cost_micro_usd), 0) AS cost_micro_usd, COALESCE(SUM(turns), 0) AS turns,
                           COALESCE(SUM(duration_ms), 0) AS duration_ms,
                           MAX(NULLIF(CASE WHEN step = \'builder\' THEN model ELSE \'\' END, \'\')) AS model
                      FROM ai_usage_event WHERE scan_id = :scan
               ) t
              WHERE s.id = :scan',
            ['scan' => $scanId],
        );
    }
}
