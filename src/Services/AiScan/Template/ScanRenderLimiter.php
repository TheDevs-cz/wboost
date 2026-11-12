<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Template;

use Doctrine\DBAL\Connection;

/**
 * At most TWO scan-originated renders at once (scanner calls and, later,
 * review fixes) — so scans can never occupy more than two of `web`'s workers
 * or flood Gotenberg.
 *
 * Two Postgres advisory locks taken with `pg_try_advisory_lock`: a third
 * caller is told "busy" at once instead of queueing, and a request that dies
 * mid-render (a timeout fatal, a killed worker) loses its database session,
 * which releases the lock by itself — nothing to clean up, no stale counter.
 */
final readonly class ScanRenderLimiter
{
    /** "wbAI" + slot — an arbitrary, stable key pair. */
    public const int LOCK_CLASS = 0x77624149;
    public const int SLOTS = 2;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return null|int the slot taken, or null when both are in use
     */
    public function acquire(): null|int
    {
        for ($slot = 1; $slot <= self::SLOTS; $slot++) {
            if ($this->connection->fetchOne('SELECT pg_try_advisory_lock(:class, :slot)', ['class' => self::LOCK_CLASS, 'slot' => $slot]) === true) {
                return $slot;
            }
        }

        return null;
    }

    public function release(int $slot): void
    {
        $this->connection->fetchOne('SELECT pg_advisory_unlock(:class, :slot)', ['class' => self::LOCK_CLASS, 'slot' => $slot]);
    }
}
