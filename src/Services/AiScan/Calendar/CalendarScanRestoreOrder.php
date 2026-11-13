<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Calendar;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Services\AiScan\AiScanTime;

/**
 * Which applied scan of a calendar „Vrátit původní rozvržení“ belongs to.
 *
 * Scans applied to one calendar form a stack: scan A, then scan B — B's
 * snapshot is A's layout (with A's marker), A's snapshot is what the calendar
 * had before any scan. Only the TOP of that stack can be restored: restoring B
 * brings A's layout back and makes A the top again, so its own restore is
 * offered as before, as long as its snapshot lives. Restoring A while B is on
 * top would write the pre-scan layout under B's snapshot — B's restore would
 * then bring a scanned layout back over it. Nothing offers that, and
 * {@see \WBoost\Web\MessageHandler\AiScan\RestoreCalendarScanHandler} refuses it.
 *
 * The order is the order of the APPLY — `restore.appliedRevision`, the
 * calendar's revision right after the accept —, not of the scans: a proposal
 * may wait for days before it is applied.
 */
final readonly class CalendarScanRestoreOrder
{
    private const string ORDER = "CASE WHEN restore->>'appliedRevision' ~ '^[0-9]+$' THEN CAST(restore->>'appliedRevision' AS BIGINT) ELSE 0 END DESC, finished_at DESC, id DESC";

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The calendar's applied scan that can be restored now: applied last,
     * not restored, its snapshot still there. With `$projectId` the calendar
     * must be that project's (a caller checked against the project only).
     *
     * @return null|array{scanId: string, restoreUntil: string}
     */
    public function top(string $calendarId, null|string $projectId = null): null|array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT id, expires_at FROM ai_scan
              WHERE calendar_id = :calendar AND kind = 'calendar' AND status = 'accepted' AND restore IS NOT NULL
                AND NOT jsonb_exists(restore, 'restoredAt') AND expires_at > :now"
                . ($projectId === null ? '' : ' AND project_id = :project')
                . ' ORDER BY ' . self::ORDER . ' LIMIT 1',
            ['calendar' => $calendarId, 'now' => AiScanTime::sql($this->clock->now())] + ($projectId === null ? [] : ['project' => $projectId]),
        );

        $until = is_array($row) && is_string($row['expires_at']) ? AiScanTime::fromSql($row['expires_at']) : null;

        return is_array($row) && is_string($row['id']) && $until !== null ? ['scanId' => $row['id'], 'restoreUntil' => $until->format(DATE_ATOM)] : null;
    }

    /** Is this scan the one its calendar can restore now? */
    public function isTop(AiScan $scan): bool
    {
        if ($scan->calendar === null) {
            return false;
        }

        $top = $this->top($scan->calendar->id->toString());

        return $top !== null && $top['scanId'] === $scan->id->toString();
    }
}
