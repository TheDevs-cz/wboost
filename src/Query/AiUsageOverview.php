<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use WBoost\Web\Services\AiScan\AiScanRuntimeSnapshot;

/**
 * What the admin tab „AI skeny“ shows — see {@see GetAiUsageOverview}.
 *
 * @phpstan-type Request array{id: string, projectId: string, projectName: string, requesterName: string, requesterEmail: string, origin: string, message: null|string, createdAt: null|string}
 * @phpstan-type ProjectRow array{id: string, name: string, ownerName: null|string, ownerEmail: string, enabled: bool, limit: null|int, month: int, total: int, costMonthMicroUsd: int, costTotalMicroUsd: int}
 * @phpstan-type ScanRow array{id: string, projectId: string, projectName: string, user: string, kind: string, status: string, code: null|string, inProgress: bool, createdAt: null|\DateTimeImmutable, try: int, restarted: bool, seconds: null|int, tokens: int, tokensIn: int, tokensOut: int, tokensCache: int, costMicroUsd: int, credit: null|string, rating: null|int, ratingNote: null|string, channel: string, peakRssMb: null|int, cpuSeconds: null|int}
 */
#[Exclude]
final readonly class AiUsageOverview
{
    /**
     * @param list<string> $notices codes of the strip's rose notices: `scanner_absent`, `scanner_too_old`, `provider_blocked`, `spend_ceiling`, `breaker_tripped`
     * @param list<Request> $requests
     * @param list<ProjectRow> $projects
     * @param list<ScanRow> $scans in progress first (oldest first), then the last ended ones
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
    ) {
    }

    public function inProgress(): int
    {
        return $this->waiting + $this->running;
    }
}
