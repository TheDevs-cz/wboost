<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use WBoost\Web\Services\AiScan\AiScanRuntimeSnapshot;

/**
 * What the admin tab „AI skeny“ shows — see {@see GetAiUsageOverview}.
 *
 * @phpstan-type Request array{id: string, projectId: string, projectName: string, requesterName: string, requesterEmail: string, origin: string, message: null|string, createdAt: null|string}
 * @phpstan-type ProjectRow array{id: string, name: string, deleted: bool, ownerName: null|string, ownerEmail: string, enabled: bool, limit: null|int, month: int, total: int, costMonthMicroUsd: int, costTotalMicroUsd: int, refusalsToday: int, paused: bool}
 * @phpstan-type ScanRow array{id: string, projectId: string, projectName: string, calendarId: null|string, user: string, kind: string, status: string, code: null|string, inProgress: bool, createdAt: null|\DateTimeImmutable, try: int, restarted: bool, seconds: null|int, tokens: int, tokensIn: int, tokensOut: int, tokensCache: int, costMicroUsd: int, credit: null|string, rating: null|int, ratingNote: null|string, channel: string, peakRssMb: null|int, cpuSeconds: null|int}
 * @phpstan-type UsageRow array{scanId: string, run: int, step: string, model: string, occurredAt: null|\DateTimeImmutable, closed: bool, seconds: null|int, turns: int, tokensIn: int, tokensOut: int, tokensCacheRead: int, tokensCacheWrite: int, costMicroUsd: int, outcome: null|string, code: null|string}
 */
#[Exclude]
final readonly class AiUsageOverview
{
    /**
     * @param list<string> $notices codes of the strip's rose notices: `scanner_absent`, `scanner_too_old`, `provider_blocked`, `spend_ceiling`, `breaker_tripped`
     * @param list<Request> $requests
     * @param list<ProjectRow> $projects the living projects, then the deleted ones that left spend behind (`deleted` — named by their usage rows' snapshot, '' when unknown)
     * @param list<ScanRow> $scans in progress first (oldest first, page 1 only), then one page of the ended ones — or the one scan of `?ai_scan=`
     * @param int $scansTotal scans under the current filter
     * @param int $scanPage the page of ended scans shown (1-based)
     * @param int $scanPages how many pages of ended scans there are (at least 1)
     * @param null|string $filterScanId `?ai_scan=`: only this scan (the row may be gone — its usage rows stay)
     * @param null|array{id: string, name: string, deleted: bool} $filterProject `?ai_project=`: only this project's scans (`deleted`: the project is gone, only its spend is left)
     * @param list<UsageRow> $scanUsage every usage row of the filtered scan, by run and step — or the newest usage rows of the filtered DELETED project
     */
    public function __construct(
        public AiScanRuntimeSnapshot $runtime,
        public int $waiting,
        public int $running,
        public null|int $oldestWaitingSeconds,
        public null|int $scannerSeenSeconds,
        public int $spentTodayMicroUsd,
        public int $reservedMicroUsd,
        public int $spentMonthMicroUsd,
        public array $notices,
        public array $requests,
        public array $projects,
        public array $scans,
        public int $scansTotal,
        public int $scanPage = 1,
        public int $scanPages = 1,
        public null|string $filterScanId = null,
        public null|array $filterProject = null,
        public array $scanUsage = [],
    ) {
    }

    public function inProgress(): int
    {
        return $this->waiting + $this->running;
    }
}
