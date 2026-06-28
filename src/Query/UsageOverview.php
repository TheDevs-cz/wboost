<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

/**
 * The whole admin usage report view-model: per-owner ▸ per-project breakdown,
 * the list of months present in the data, grand totals, and a ready-to-render
 * ApexCharts dataset (unique templates per client per month).
 */
readonly final class UsageOverview
{
    /**
     * @param list<string> $months 'YYYY-MM' ascending — every month that has data
     * @param list<UsageOwnerRow> $owners ordered by total downloads desc
     * @param list<string> $chartCategories x-axis labels ('M/YYYY') aligned to $months
     * @param list<array{name: string, data: list<int>}> $chartSeries one stacked series per client
     */
    public function __construct(
        public array $months,
        public array $owners,
        public int $totalUniqueTemplates,
        public int $totalUniqueVariants,
        public int $totalDownloads,
        public int $clientCount,
        public int $projectCount,
        public array $chartCategories,
        public array $chartSeries,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->owners === [];
    }
}
