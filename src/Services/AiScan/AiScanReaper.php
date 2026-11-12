<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\AiScan\AiScanStatus;

/**
 * The ONE writer of time-based transitions: a lost intake lease (rules 3a /
 * 3b), a lost build lease (7 / 6) and the queue timeout (11).
 *
 * Called by every scanner `claim` and `tick`, by the nightly purge — and by
 * any status or list read that meets an overdue row ({@see reapIfOverdue()}),
 * so a dead scanner delays nothing a user is looking at. Racing callers are
 * harmless: each transition is a conditional update and happens once.
 */
final readonly class AiScanReaper
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private AiScanTransitions $transitions,
    ) {
    }

    /**
     * @return int how many scans it moved
     */
    public function run(): int
    {
        $now = $this->clock->now();

        /** @var list<array{id: string, status: string}> $overdue */
        $overdue = $this->connection->fetchAllAssociative(
            "SELECT id, status FROM ai_scan
              WHERE (status IN ('checking', 'running') AND lease_expires_at IS NOT NULL AND lease_expires_at < :now)
                 OR (status IN ('checking', 'queued') AND waiting_since IS NOT NULL AND waiting_since < :since)
              ORDER BY created_at
              LIMIT 200",
            ['now' => AiScanTime::sql($now), 'since' => AiScanTime::sql($now->modify(sprintf('-%d seconds', AiScanLimits::QUEUE_TIMEOUT_SECONDS)))],
        );

        $moved = 0;

        foreach ($overdue as $row) {
            if ($this->reap($row['id'], AiScanStatus::from($row['status']))) {
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * The lazy path of a read: acts only when this row is overdue. Returns
     * true when the row changed (the caller re-reads it).
     */
    public function reapIfOverdue(AiScan $scan): bool
    {
        if (!self::isOverdue($scan, $this->clock->now())) {
            return false;
        }

        return $this->reap($scan->id->toString(), $scan->status);
    }

    public static function isOverdue(AiScan $scan, DateTimeImmutable $now): bool
    {
        if (!$scan->status->isInProgress()) {
            return false;
        }

        if ($scan->leaseExpiresAt !== null && $scan->leaseExpiresAt < $now) {
            return true;
        }

        return $scan->status !== AiScanStatus::Running
            && $scan->waitingSince !== null
            && $now->getTimestamp() - $scan->waitingSince->getTimestamp() > AiScanLimits::QUEUE_TIMEOUT_SECONDS;
    }

    private function reap(string $id, AiScanStatus $status): bool
    {
        // The queue timeout first: a scan that waited an hour is over, however
        // its lease looks. Each call is a no-op unless its own condition holds.
        if ($status !== AiScanStatus::Running && $this->transitions->queueTimeout($id)) {
            return true;
        }

        return match ($status) {
            AiScanStatus::Checking => $this->transitions->intakeLeaseLost($id),
            AiScanStatus::Running => $this->transitions->buildLeaseLost($id),
            default => false,
        };
    }
}
