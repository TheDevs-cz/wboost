<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\AiScan;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\AiScan\AiScanQueueEstimate;
use WBoost\Web\Value\AiScan\QueueEstimate;

/**
 * The queue estimate's arithmetic (architecture.md §5.1).
 */
final class AiScanQueueEstimateTest extends TestCase
{
    private const string NOW = '2026-11-12 12:00:00';

    public function testDefaultsApplyUntilTenFinishedRunsExist(): void
    {
        self::assertSame([60, 150], AiScanQueueEstimate::percentiles('template', []));
        self::assertSame([60, 120], AiScanQueueEstimate::percentiles('calendar', [10, 20, 30, 40, 50, 60, 70, 80, 90]));
        // An unknown kind falls back to the template defaults rather than failing.
        self::assertSame([60, 150], AiScanQueueEstimate::percentiles('other', []));
    }

    public function testPercentilesComeFromTheSampleFailedRunsIncluded(): void
    {
        // Twelve runs: eight ordinary ones and four that failed late (long).
        // The caller hands in EVERY finished run; nothing here filters by outcome.
        $durations = [40, 50, 60, 70, 80, 90, 100, 110, 300, 320, 340, 360];

        self::assertSame([60, 300], AiScanQueueEstimate::percentiles('template', $durations));
    }

    public function testNobodyAheadStartsAtOnce(): void
    {
        $estimate = $this->compute('template', []);

        self::assertSame(0, $estimate->position);
        self::assertSame([0, 0], $estimate->startSeconds);
        self::assertSame([60, 150], $estimate->finishSeconds);
    }

    public function testWaitingScansAheadAddTheirWholeDuration(): void
    {
        $estimate = $this->compute('template', [
            ['kind' => 'template', 'status' => 'queued', 'claimed_at' => null, 'auto_restart' => 0],
            ['kind' => 'calendar', 'status' => 'checking', 'claimed_at' => null, 'auto_restart' => 0],
        ]);

        self::assertSame(2, $estimate->position);
        self::assertSame([120, 270], $estimate->startSeconds);
        self::assertSame([180, 420], $estimate->finishSeconds);
    }

    public function testARunningScanCountsWhatItStillNeedsFlooredAtThirtySeconds(): void
    {
        // Claimed 100 s ago: needs 60−100 → floor 30, and 150−100 = 50.
        $estimate = $this->compute('template', [
            ['kind' => 'template', 'status' => 'running', 'claimed_at' => '2026-11-12 11:58:20', 'auto_restart' => 0],
        ]);

        self::assertSame(1, $estimate->position);
        self::assertSame([30, 50], $estimate->startSeconds);
    }

    public function testARunOnItsAutomaticRestartAddsTheRunCap(): void
    {
        $estimate = $this->compute('template', [
            ['kind' => 'template', 'status' => 'running', 'claimed_at' => '2026-11-12 11:59:50', 'auto_restart' => 1],
        ]);

        // (60−10) + 360 and (150−10) + 360.
        self::assertSame([410, 500], $estimate->startSeconds);
    }

    public function testMoreWorkersDivideTheWait(): void
    {
        $ahead = array_fill(0, 4, ['kind' => 'template', 'status' => 'queued', 'claimed_at' => null, 'auto_restart' => 0]);

        self::assertSame([240, 600], $this->compute('template', $ahead, 1)->startSeconds);
        self::assertSame([120, 300], $this->compute('template', $ahead, 2)->startSeconds);
    }

    public function testNoneIsAllNull(): void
    {
        $none = QueueEstimate::none();

        self::assertNull($none->position);
        self::assertNull($none->startSeconds);
        self::assertNull($none->finishSeconds);
    }

    /**
     * @param list<array{kind: string, status: string, claimed_at: null|string, auto_restart: int|string}> $ahead
     */
    private function compute(string $kind, array $ahead, int $maxRunning = 1): QueueEstimate
    {
        return AiScanQueueEstimate::compute(
            $kind,
            $ahead,
            static fn (string $k): array => AiScanQueueEstimate::percentiles($k, []),
            $maxRunning,
            new DateTimeImmutable(self::NOW),
        );
    }
}
