<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\AiScan\AiScanMode;
use WBoost\Web\Value\AiScan\ProviderState;

/**
 * Reads and writes the one `ai_scan_runtime` row. Never cached: the operating
 * mode must take effect on the next request, and the read is a primary-key
 * lookup of a single row.
 */
final readonly class AiScanRuntimeState
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function get(): AiScanRuntimeSnapshot
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ai_scan_runtime WHERE id = 1');

        if ($row === false) {
            // The migration inserts the row; a database built from the schema
            // alone (tests) gets it here. The column defaults are the safe
            // ones: mode off, admins only.
            $this->connection->executeStatement('INSERT INTO ai_scan_runtime (id) VALUES (1) ON CONFLICT (id) DO NOTHING');
            $row = $this->connection->fetchAssociative('SELECT * FROM ai_scan_runtime WHERE id = 1');
        }

        assert(is_array($row));

        return new AiScanRuntimeSnapshot(
            mode: AiScanMode::tryFrom(self::string($row['mode'])) ?? AiScanMode::Off,
            adminOnly: (bool) $row['admin_only'],
            maxRunning: max(1, self::int($row['max_running'])),
            dailyCeilingMicroUsd: self::int($row['daily_ceiling_micro_usd']),
            projectDailyMicroUsd: self::int($row['project_daily_micro_usd']),
            dailyStartsUser: self::int($row['daily_starts_user']),
            dailyStartsProject: self::int($row['daily_starts_project']),
            providerState: ProviderState::tryFrom(self::string($row['provider_state'])) ?? ProviderState::Ok,
            providerCheckedAt: AiScanTime::fromSql(self::nullableString($row['provider_checked_at'])),
            providerError: self::nullableString($row['provider_error']),
            scannerSeenAt: AiScanTime::fromSql(self::nullableString($row['scanner_seen_at'])),
            scannerVersion: self::nullableString($row['scanner_version']),
            scannerTooOld: (bool) $row['scanner_too_old'],
            consecutiveFailures: self::int($row['consecutive_failures']),
            failureClass: self::nullableString($row['failure_class']),
            // (`?? null`: the column arrives with a migration; a row read before it has none.)
            breakerTrippedAt: AiScanTime::fromSql(self::nullableString($row['breaker_tripped_at'] ?? null)),
        );
    }

    /**
     * Settings an admin changes. Null = leave as it is. The side effect of
     * mode `off` (failing scans in progress) belongs to
     * {@see \WBoost\Web\MessageHandler\AiScan\SetAiScanRuntimeHandler}.
     */
    public function configure(
        null|AiScanMode $mode = null,
        null|bool $adminOnly = null,
        null|int $maxRunning = null,
        null|int $dailyCeilingMicroUsd = null,
        null|int $projectDailyMicroUsd = null,
        null|int $dailyStartsUser = null,
        null|int $dailyStartsProject = null,
    ): void {
        $this->get();

        $set = [];
        $params = [];

        if ($mode !== null) {
            $set[] = 'mode = :mode';
            // A mode set by hand ends "paused by the circuit breaker" — also
            // `paused` again: the admin has taken over.
            $set[] = 'breaker_tripped_at = NULL';
            $params['mode'] = $mode->value;

            if ($mode === AiScanMode::On) {
                // Switching (back) on by hand also closes the circuit breaker;
                // setting `on` while it is on changes nothing.
                $set[] = "consecutive_failures = CASE WHEN mode <> 'on' THEN 0 ELSE consecutive_failures END";
                $set[] = "failure_class = CASE WHEN mode <> 'on' THEN NULL ELSE failure_class END";
            }
        }

        if ($adminOnly !== null) {
            $set[] = 'admin_only = :adminOnly';
            $params['adminOnly'] = $adminOnly ? 'true' : 'false';
        }

        foreach ([
            'max_running' => $maxRunning === null ? null : max(1, min($maxRunning, 8)),
            'daily_ceiling_micro_usd' => $dailyCeilingMicroUsd === null ? null : max(0, $dailyCeilingMicroUsd),
            'project_daily_micro_usd' => $projectDailyMicroUsd === null ? null : max(0, $projectDailyMicroUsd),
            'daily_starts_user' => $dailyStartsUser === null ? null : max(0, $dailyStartsUser),
            'daily_starts_project' => $dailyStartsProject === null ? null : max(0, $dailyStartsProject),
        ] as $column => $value) {
            if ($value !== null) {
                $set[] = sprintf('%s = :%s', $column, $column);
                $params[$column] = $value;
            }
        }

        if ($set === []) {
            return;
        }

        $this->connection->executeStatement(sprintf('UPDATE ai_scan_runtime SET %s WHERE id = 1', implode(', ', $set)), $params);
    }

    /**
     * An authenticated scanner call was just served. Written at most every
     * five seconds — the "absent" threshold is a minute.
     */
    public function scannerSeen(null|string $version): void
    {
        $now = $this->clock->now();

        $this->connection->executeStatement(
            'UPDATE ai_scan_runtime
                SET scanner_seen_at = :now, scanner_version = COALESCE(:version, scanner_version), scanner_too_old = false
              WHERE id = 1 AND (scanner_seen_at IS NULL OR scanner_seen_at < :stale OR scanner_too_old
                    OR (CAST(:version AS VARCHAR) IS NOT NULL AND scanner_version IS DISTINCT FROM :version))',
            [
                'now' => AiScanTime::sql($now),
                'stale' => AiScanTime::sql($now->modify('-5 seconds')),
                'version' => $version,
            ],
        );
    }

    /** A scanner the app cannot work with called (protocol or instruments): it is seen, and too old. */
    public function scannerTooOld(null|string $version): void
    {
        $this->get();

        $this->connection->executeStatement(
            'UPDATE ai_scan_runtime SET scanner_seen_at = :now, scanner_version = COALESCE(:version, scanner_version), scanner_too_old = true WHERE id = 1',
            ['now' => AiScanTime::sql($this->clock->now()), 'version' => $version],
        );
    }

    public function providerReported(ProviderState $state, null|string $error): void
    {
        $this->get();

        $this->connection->executeStatement(
            'UPDATE ai_scan_runtime SET provider_state = :state, provider_checked_at = :now, provider_error = :error WHERE id = 1',
            [
                'state' => $state->value,
                'now' => AiScanTime::sql($this->clock->now()),
                'error' => $state === ProviderState::Ok ? null : self::code($error),
            ],
        );
    }

    /**
     * Circuit breaker: a scan ended. `$failureClass` null = it did not fail
     * for a system reason (the streak ends). Returns true when this failure
     * was the third in a row of its class and the mode was just set to
     * `paused`.
     */
    public function scanEnded(null|string $failureClass): bool
    {
        if ($failureClass === null) {
            $this->connection->executeStatement(
                'UPDATE ai_scan_runtime SET consecutive_failures = 0, failure_class = NULL WHERE id = 1 AND consecutive_failures <> 0',
            );

            return false;
        }

        $count = $this->connection->fetchOne(
            'UPDATE ai_scan_runtime
                SET consecutive_failures = CASE WHEN failure_class = :class THEN consecutive_failures + 1 ELSE 1 END,
                    failure_class = :class
              WHERE id = 1
          RETURNING consecutive_failures',
            ['class' => $failureClass],
        );

        if (!is_numeric($count) || (int) $count < AiScanLimits::BREAKER_THRESHOLD) {
            return false;
        }

        return $this->connection->executeStatement(
            "UPDATE ai_scan_runtime SET mode = 'paused', breaker_tripped_at = :now WHERE id = 1 AND mode = 'on'",
            ['now' => AiScanTime::sql($this->clock->now())],
        ) === 1;
    }

    private static function code(null|string $value): null|string
    {
        if ($value === null) {
            return null;
        }

        $code = preg_replace('/[^A-Za-z0-9._:\- ]/', '', $value) ?? '';

        return $code === '' ? null : substr($code, 0, 64);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function nullableString(mixed $value): null|string
    {
        return is_string($value) ? $value : null;
    }
}
