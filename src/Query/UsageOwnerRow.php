<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use DateTimeImmutable;

/**
 * One "client" (project owner account) in the usage report, with every project
 * the account owns nested underneath.
 *
 * Owner totals are the sum of the per-project totals — valid because a template
 * / variant belongs to exactly one project, so the same id never appears under
 * two projects of the same owner.
 */
readonly final class UsageOwnerRow
{
    /**
     * @param list<UsageProjectRow> $projects
     */
    public function __construct(
        public string $ownerId,
        public string $ownerEmail,
        public null|string $ownerName,
        public int $uniqueTemplates,
        public int $uniqueVariants,
        public int $downloads,
        public DateTimeImmutable $lastExportAt,
        public array $projects,
    ) {
    }

    /** The account's name, or its e-mail when it has none (the report's row label). */
    public function displayName(): string
    {
        return $this->ownerName ?? $this->ownerEmail;
    }

    public function templatesInMonth(string $month): int
    {
        $sum = 0;

        foreach ($this->projects as $project) {
            $sum += $project->templatesInMonth($month);
        }

        return $sum;
    }

    public function downloadsInMonth(string $month): int
    {
        $sum = 0;

        foreach ($this->projects as $project) {
            $sum += $project->downloadsInMonth($month);
        }

        return $sum;
    }
}
