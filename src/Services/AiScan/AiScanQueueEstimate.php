<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\AiScan\AiScanStatus;
use WBoost\Web\Value\AiScan\AiScanWaiting;
use WBoost\Web\Value\AiScan\QueueEstimate;

/**
 * "How many are ahead and when will mine start" for a `checking` / `queued`
 * scan (architecture.md §5.1). Computed from the database at every call —
 * three small indexed queries — so it is never older than the request.
 *
 * - position = scans ahead, running ones included;
 * - duration per kind = p25–p75 of the last 50 finished runs of that kind,
 *   FAILED ONES INCLUDED (defaults until 10 exist);
 * - start = (what the running scans still need, floored at 30 s, plus the run
 *   cap for a run on its automatic restart, plus the durations of those
 *   ahead) ÷ `max_running`; finish = start + own duration.
 *
 * All null while the queue is not moving.
 */
final readonly class AiScanQueueEstimate
{
    private const int SAMPLE = 50;
    private const int MIN_SAMPLE = 10;
    private const int RUNNING_FLOOR_SECONDS = 30;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function for(AiScan $scan, AiScanRuntimeSnapshot $runtime, null|AiScanWaiting $waiting): QueueEstimate
    {
        if ($waiting !== null || !in_array($scan->status, [AiScanStatus::Checking, AiScanStatus::Queued], true)) {
            return QueueEstimate::none();
        }

        $now = $this->clock->now();

        /** @var list<array{kind: string, status: string, claimed_at: null|string, auto_restart: int|string}> $ahead */
        $ahead = $this->connection->fetchAllAssociative(
            "SELECT kind, status, claimed_at, auto_restart FROM ai_scan
              WHERE id <> :id
                AND (status = 'running'
                     OR (status IN ('checking', 'queued') AND (waiting_since < :since OR (waiting_since = :since AND id < :id))))",
            ['id' => $scan->id->toString(), 'since' => AiScanTime::sql($scan->waitingSince ?? $scan->createdAt)],
        );

        return self::compute(
            $scan->kind->value,
            $ahead,
            fn (string $kind): array => $this->duration($kind),
            $runtime->maxRunning,
            $now,
        );
    }

    /**
     * The same estimate for a scan that would be started NOW (the queue line
     * of the start screen): everything waiting or running is ahead of it.
     */
    public function forNew(AiScanKind $kind, AiScanRuntimeSnapshot $runtime): QueueEstimate
    {
        $now = $this->clock->now();

        if ($runtime->waiting($now) !== null) {
            return QueueEstimate::none();
        }

        /** @var list<array{kind: string, status: string, claimed_at: null|string, auto_restart: int|string}> $ahead */
        $ahead = $this->connection->fetchAllAssociative(
            "SELECT kind, status, claimed_at, auto_restart FROM ai_scan WHERE status IN ('checking', 'queued', 'running')",
        );

        return self::compute($kind->value, $ahead, fn (string $name): array => $this->duration($name), $runtime->maxRunning, $now);
    }

    /**
     * The arithmetic, free of the database (unit-tested).
     *
     * @param list<array{kind: string, status: string, claimed_at: null|string, auto_restart: int|string}> $ahead
     * @param callable(string): array{int, int} $duration [p25, p75] seconds per kind
     */
    public static function compute(string $kind, array $ahead, callable $duration, int $maxRunning, DateTimeImmutable $now): QueueEstimate
    {
        $from = 0.0;
        $to = 0.0;

        foreach ($ahead as $other) {
            [$low, $high] = $duration($other['kind']);

            if ($other['status'] !== AiScanStatus::Running->value) {
                $from += $low;
                $to += $high;

                continue;
            }

            $ran = max(0, $now->getTimestamp() - (AiScanTime::fromSql($other['claimed_at'])?->getTimestamp() ?? $now->getTimestamp()));
            $penalty = (int) $other['auto_restart'] >= 1 ? AiScanLimits::RUN_CAP_SECONDS : 0;

            $from += max(self::RUNNING_FLOOR_SECONDS, $low - $ran) + $penalty;
            $to += max(self::RUNNING_FLOOR_SECONDS, $high - $ran) + $penalty;
        }

        $workers = max(1, $maxRunning);
        [$ownLow, $ownHigh] = $duration($kind);
        $start = [(int) round($from / $workers), (int) round($to / $workers)];

        return new QueueEstimate(count($ahead), $start, [$start[0] + $ownLow, $start[1] + $ownHigh]);
    }

    /**
     * @param list<int> $seconds durations of finished runs
     * @return array{int, int}
     */
    public static function percentiles(string $kind, array $seconds): array
    {
        if (count($seconds) < self::MIN_SAMPLE) {
            return match ($kind) {
                'calendar' => AiScanLimits::DEFAULT_DURATION['calendar'],
                default => AiScanLimits::DEFAULT_DURATION['template'],
            };
        }

        sort($seconds);
        $at = static fn (float $share): int => $seconds[(int) floor($share * (count($seconds) - 1))];

        return [$at(0.25), max($at(0.25), $at(0.75))];
    }

    /**
     * @return array{int, int}
     */
    private function duration(string $kind): array
    {
        /** @var list<int|string> $durations */
        $durations = $this->connection->fetchFirstColumn(
            "SELECT EXTRACT(EPOCH FROM (closed_at - occurred_at))::int FROM ai_usage_event
              WHERE kind = :kind AND step = 'builder' AND closed_at IS NOT NULL
                AND outcome IN ('proposed', 'failed', 'refused')
              ORDER BY closed_at DESC LIMIT " . self::SAMPLE,
            ['kind' => $kind],
        );

        return self::percentiles($kind, array_map(static fn (int|string $value): int => max(1, (int) $value), $durations));
    }
}
