<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use WBoost\Web\Services\AiScan\AiAccessRequests;
use WBoost\Web\Services\AiScan\AiScanRuntimeState;
use WBoost\Web\Services\AiScan\AiScanSpend;
use WBoost\Web\Services\AiScan\AiScanTime;
use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\AiScan\AiScanMode;
use WBoost\Web\Value\AiScan\ProviderState;

/**
 * The read side of the admin tab „AI skeny“ on `/admin/usage` (ux.md §9.4) —
 * the web version of `app:ai-scan:status`: the runtime row, the queue, the
 * spend of today and of the month, the open access requests, every project
 * with its entitlement and use (and whether its starts are paused for today:
 * three refusals by the provider), and the scans in progress plus one page
 * of the ended ones. Pure read model: raw SQL, no entity, no reaper.
 *
 * Two filters, both by link (a URL contract — `AdminUsageController`):
 * one scan with every usage row of it, or one project's scans.
 *
 * Ids, codes, numbers and e-mail addresses only — nothing of a picture.
 *
 * @phpstan-import-type ProjectRow from AiUsageOverview
 * @phpstan-import-type ScanRow from AiUsageOverview
 * @phpstan-import-type UsageRow from AiUsageOverview
 */
final readonly class GetAiUsageOverview
{
    /** Ended scans on one page, under the ones in progress. */
    public const int ENDED_LIMIT = 50;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private AiScanRuntimeState $runtime,
        private AiScanSpend $spend,
        private AiAccessRequests $requests,
    ) {
    }

    /**
     * @param int $page the page of ended scans, 1-based; out of range = the nearest page
     * @param null|string $scanId a valid uuid: only this scan, with its usage rows (wins over `$projectId`)
     * @param null|string $projectId a valid uuid: only this project's scans; an unknown project is no filter
     */
    public function overview(int $page = 1, null|string $scanId = null, null|string $projectId = null): AiUsageOverview
    {
        $now = $this->clock->now();
        $runtime = $this->runtime->get();

        $waiting = self::int($this->connection->fetchOne("SELECT COUNT(*) FROM ai_scan WHERE status IN ('checking', 'queued')"));
        $running = self::int($this->connection->fetchOne("SELECT COUNT(*) FROM ai_scan WHERE status = 'running'"));
        $oldest = AiScanTime::fromSql(self::nullableString($this->connection->fetchOne(
            "SELECT MIN(waiting_since) FROM ai_scan WHERE status IN ('checking', 'queued')",
        )));

        $today = $this->spend->totalToday($now);
        $notices = [];

        // While the feature is switched off on purpose nothing is "wrong".
        if ($runtime->mode !== AiScanMode::Off) {
            if ($runtime->scannerTooOld) {
                $notices[] = 'scanner_too_old';
            } elseif ($runtime->scannerAbsent($now)) {
                $notices[] = 'scanner_absent';
            }

            if ($runtime->providerState === ProviderState::Blocked) {
                $notices[] = 'provider_blocked';
            }

            if ($today + $this->spend->runBudget() > $runtime->dailyCeilingMicroUsd) {
                $notices[] = 'spend_ceiling';
            }

            if ($runtime->mode === AiScanMode::Paused && $runtime->breakerTrippedAt !== null) {
                $notices[] = 'breaker_tripped';
            }
        }

        $list = $this->scanList($now, $page, $scanId, $projectId);

        return new AiUsageOverview(
            runtime: $runtime,
            waiting: $waiting,
            running: $running,
            oldestWaitingSeconds: $oldest === null ? null : max(0, $now->getTimestamp() - $oldest->getTimestamp()),
            scannerSeenSeconds: $runtime->scannerSeenAt === null ? null : max(0, $now->getTimestamp() - $runtime->scannerSeenAt->getTimestamp()),
            spentTodayMicroUsd: $today,
            reservedMicroUsd: $this->spend->reservedNow(),
            spentMonthMicroUsd: $this->spend->recordedThisMonth($now),
            notices: $notices,
            requests: $this->requests->open(),
            projects: $this->projects($now),
            scans: $list['scans'],
            scansTotal: $list['scansTotal'],
            scanPage: $list['scanPage'],
            scanPages: $list['scanPages'],
            filterScanId: $list['filterScanId'],
            filterProject: $list['filterProject'],
            scanUsage: $list['scanUsage'],
        );
    }

    /**
     * @return array{scans: list<ScanRow>, scansTotal: int, scanPage: int, scanPages: int, filterScanId: null|string, filterProject: null|array{id: string, name: string}, scanUsage: list<UsageRow>}
     */
    private function scanList(DateTimeImmutable $now, int $page, null|string $scanId, null|string $projectId): array
    {
        if ($scanId !== null) {
            $scans = $this->scans($now, 's.id = :id', ['id' => $scanId], 1, 0);

            return [
                'scans' => $scans,
                'scansTotal' => count($scans),
                'scanPage' => 1,
                'scanPages' => 1,
                'filterScanId' => $scanId,
                'filterProject' => null,
                'scanUsage' => $this->usage($scanId),
            ];
        }

        $project = null;

        if ($projectId !== null) {
            $name = $this->connection->fetchOne('SELECT name FROM project WHERE id = :id', ['id' => $projectId]);
            $project = is_string($name) ? ['id' => $projectId, 'name' => $name] : null;
        }

        $where = $project === null ? 'TRUE' : 's.project_id = :project';
        $params = $project === null ? [] : ['project' => $project['id']];

        $ended = self::int($this->connection->fetchOne(
            "SELECT COUNT(*) FROM ai_scan s WHERE $where AND s.status NOT IN ('checking', 'queued', 'running')",
            $params,
        ));
        $total = self::int($this->connection->fetchOne("SELECT COUNT(*) FROM ai_scan s WHERE $where", $params));
        $pages = max(1, (int) ceil($ended / self::ENDED_LIMIT));
        $page = max(1, min($page, $pages));

        return [
            'scans' => $this->scans($now, $where, $params, $page, ($page - 1) * self::ENDED_LIMIT),
            'scansTotal' => $total,
            'scanPage' => $page,
            'scanPages' => $pages,
            'filterScanId' => null,
            'filterProject' => $project,
            'scanUsage' => [],
        ];
    }

    /**
     * Every usage row of a scan — they outlive the scan row.
     *
     * @return list<UsageRow>
     */
    private function usage(string $scanId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT run, step, model, occurred_at, closed_at, duration_ms, turns, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens,
                    cost_micro_usd, outcome, error_code
               FROM ai_usage_event WHERE scan_id = :scan ORDER BY occurred_at, run, step',
            ['scan' => $scanId],
        );

        return array_map(static function (array $row): array {
            $duration = self::int($row['duration_ms']);

            return [
                'run' => self::int($row['run']),
                'step' => self::string($row['step']),
                'model' => self::string($row['model']),
                'occurredAt' => AiScanTime::fromSql(self::nullableString($row['occurred_at'])),
                'closed' => self::nullableString($row['closed_at']) !== null,
                'seconds' => $duration > 0 ? (int) round($duration / 1000) : null,
                'turns' => self::int($row['turns']),
                'tokensIn' => self::int($row['input_tokens']),
                'tokensOut' => self::int($row['output_tokens']),
                'tokensCacheRead' => self::int($row['cache_read_tokens']),
                'tokensCacheWrite' => self::int($row['cache_write_tokens']),
                'costMicroUsd' => self::int($row['cost_micro_usd']),
                'outcome' => self::nullableString($row['outcome']),
                'code' => self::nullableString($row['error_code']),
            ];
        }, $rows);
    }

    /**
     * Every project: the ones with AI scan on first, then by this month's
     * use, then by name.
     *
     * @return list<ProjectRow>
     */
    private function projects(DateTimeImmutable $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT p.id, p.name, u.name AS owner_name, u.email AS owner_email, p.ai_scan_enabled, p.ai_scan_monthly_limit,
                    COALESCE(l.month, 0) AS month, COALESCE(l.total, 0) AS total,
                    COALESCE(c.cost_month, 0) AS cost_month, COALESCE(c.cost_total, 0) AS cost_total,
                    COALESCE(c.refusals_today, 0) AS refusals_today
               FROM project p
               JOIN \"user\" u ON u.id = p.owner_id
               LEFT JOIN (
                    SELECT project_id,
                           GREATEST(0, -COALESCE(SUM(delta) FILTER (WHERE period = :period), 0)) AS month,
                           COUNT(*) FILTER (WHERE reason = 'settle') AS total
                      FROM ai_credit_ledger GROUP BY project_id
               ) l ON l.project_id = p.id
               LEFT JOIN (
                    SELECT project_id,
                           COALESCE(SUM(cost_micro_usd) FILTER (WHERE occurred_at >= :month), 0) AS cost_month,
                           COALESCE(SUM(cost_micro_usd), 0) AS cost_total,
                           COUNT(*) FILTER (WHERE outcome = 'refused' AND closed_at >= :day) AS refusals_today
                      FROM ai_usage_event GROUP BY project_id
               ) c ON c.project_id = p.id
              ORDER BY p.ai_scan_enabled DESC, COALESCE(l.month, 0) DESC, COALESCE(c.cost_total, 0) DESC, LOWER(p.name), p.id",
            [
                'period' => AiScanTime::period($now),
                'month' => AiScanTime::sql(AiScanTime::startOfDay($now)->modify('first day of this month')),
                // The same count the start guard makes (`AiScans::assertMayStart()`).
                'day' => AiScanTime::sql(AiScanTime::startOfDay($now)),
            ],
        );

        return array_map(static fn (array $row): array => [
            'id' => self::string($row['id']),
            'name' => self::string($row['name']),
            'ownerName' => self::nullableString($row['owner_name']),
            'ownerEmail' => self::string($row['owner_email']),
            'enabled' => (bool) $row['ai_scan_enabled'],
            'limit' => is_numeric($row['ai_scan_monthly_limit']) ? (int) $row['ai_scan_monthly_limit'] : null,
            'month' => self::int($row['month']),
            'total' => self::int($row['total']),
            'costMonthMicroUsd' => self::int($row['cost_month']),
            'costTotalMicroUsd' => self::int($row['cost_total']),
            'refusalsToday' => self::int($row['refusals_today']),
            'paused' => self::int($row['refusals_today']) >= AiScanLimits::PROJECT_REFUSALS_PER_DAY,
        ], $rows);
    }

    /**
     * The scans in progress (on the first page only), then `ENDED_LIMIT` ended ones from `$offset`.
     *
     * @param array<string, string> $params
     * @return list<ScanRow>
     */
    private function scans(DateTimeImmutable $now, string $where, array $params, int $page, int $offset): array
    {
        // The credit of the scan's CURRENT try: its closing line, or the open reserve.
        $columns = "s.id, s.project_id, p.name AS project_name, s.calendar_id, s.requester_email, s.kind, s.status, s.error_code, s.created_at, s.try, s.auto_restart,
                    s.duration_ms, s.waiting_since, s.claimed_at, s.input_tokens, s.output_tokens, s.cache_read_tokens, s.cache_write_tokens,
                    s.cost_micro_usd, s.rating, s.rating_note, s.channel,
                    s.resources ->> 'peakRssMb' AS peak_rss_mb, s.resources ->> 'cpuSeconds' AS cpu_seconds,
                    (SELECT l.reason FROM ai_credit_ledger l WHERE l.scan_id = s.id AND l.try = s.try
                      ORDER BY CASE l.reason WHEN 'reserve' THEN 1 ELSE 0 END LIMIT 1) AS credit";

        $inProgress = $page > 1 ? [] : $this->connection->fetchAllAssociative(
            "SELECT $columns FROM ai_scan s JOIN project p ON p.id = s.project_id
              WHERE $where AND s.status IN ('checking', 'queued', 'running') ORDER BY COALESCE(s.waiting_since, s.claimed_at, s.created_at), s.id",
            $params,
        );
        $ended = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT $columns FROM ai_scan s JOIN project p ON p.id = s.project_id
                  WHERE $where AND s.status NOT IN ('checking', 'queued', 'running') ORDER BY COALESCE(s.finished_at, s.created_at) DESC, s.id DESC LIMIT %d OFFSET %d",
                self::ENDED_LIMIT,
                max(0, $offset),
            ),
            $params,
        );

        return array_map(static function (array $row) use ($now): array {
            $inProgress = in_array($row['status'], ['checking', 'queued', 'running'], true);
            $since = AiScanTime::fromSql(self::nullableString($row['waiting_since']) ?? self::nullableString($row['claimed_at']) ?? self::nullableString($row['created_at']));
            $duration = self::int($row['duration_ms']);

            return [
                'id' => self::string($row['id']),
                'projectId' => self::string($row['project_id']),
                'projectName' => self::string($row['project_name']),
                'calendarId' => self::nullableString($row['calendar_id']),
                'user' => self::string($row['requester_email']),
                'kind' => self::string($row['kind']),
                'status' => self::string($row['status']),
                'code' => self::nullableString($row['error_code']),
                'inProgress' => $inProgress,
                'createdAt' => AiScanTime::fromSql(self::nullableString($row['created_at'])),
                'try' => self::int($row['try']),
                'restarted' => self::int($row['auto_restart']) >= 1,
                // In progress: how long it has been in this state; ended: what the scanner worked.
                'seconds' => $inProgress
                    ? ($since === null ? null : max(0, $now->getTimestamp() - $since->getTimestamp()))
                    : ($duration > 0 ? (int) round($duration / 1000) : null),
                'tokens' => self::int($row['input_tokens']) + self::int($row['output_tokens']),
                'tokensIn' => self::int($row['input_tokens']),
                'tokensOut' => self::int($row['output_tokens']),
                'tokensCache' => self::int($row['cache_read_tokens']) + self::int($row['cache_write_tokens']),
                'costMicroUsd' => self::int($row['cost_micro_usd']),
                'credit' => self::nullableString($row['credit']),
                'rating' => is_numeric($row['rating']) ? (int) $row['rating'] : null,
                'ratingNote' => self::nullableString($row['rating_note']),
                'channel' => self::string($row['channel']),
                'peakRssMb' => is_numeric($row['peak_rss_mb']) ? (int) round((float) $row['peak_rss_mb']) : null,
                'cpuSeconds' => is_numeric($row['cpu_seconds']) ? (int) round((float) $row['cpu_seconds']) : null,
            ];
        }, [...$inProgress, ...$ended]);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableString(mixed $value): null|string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
