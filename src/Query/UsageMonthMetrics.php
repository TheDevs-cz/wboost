<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

/**
 * Export metrics for a single (project, month) bucket.
 *
 * `uniqueTemplates` / `uniqueVariants` are DISTINCT counts WITHIN the month, so
 * they cannot be summed across months to obtain an all-time total (a template
 * exported in two months counts once per month) — the all-time totals are
 * computed by a separate query in {@see GetUsageOverview}.
 */
readonly final class UsageMonthMetrics
{
    public function __construct(
        public int $uniqueTemplates,
        public int $uniqueVariants,
        public int $downloads,
    ) {
    }
}
