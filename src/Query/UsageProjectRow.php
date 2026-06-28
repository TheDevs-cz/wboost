<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use DateTimeImmutable;

readonly final class UsageProjectRow
{
    /**
     * @param array<string, UsageMonthMetrics> $perMonth keyed by 'YYYY-MM'
     */
    public function __construct(
        public string $projectId,
        public string $projectName,
        public int $uniqueTemplates,
        public int $uniqueVariants,
        public int $downloads,
        public DateTimeImmutable $lastExportAt,
        public array $perMonth,
    ) {
    }

    public function templatesInMonth(string $month): int
    {
        return $this->perMonth[$month]->uniqueTemplates ?? 0;
    }

    public function variantsInMonth(string $month): int
    {
        return $this->perMonth[$month]->uniqueVariants ?? 0;
    }

    public function downloadsInMonth(string $month): int
    {
        return $this->perMonth[$month]->downloads ?? 0;
    }
}
