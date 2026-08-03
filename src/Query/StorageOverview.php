<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use DateTimeImmutable;

/**
 * View model of the admin storage report: bytes per project, rolled up per
 * client, plus the two things a per-project number alone cannot tell you —
 * how much of it is unreferenced, and how much cannot be attributed at all.
 */
readonly final class StorageOverview
{
    /**
     * @param list<StorageOwnerRow> $owners
     * @param list<StorageCategoryRow> $categories
     * @param list<string> $chartLabels
     * @param list<array{name: string, data: list<float>}> $chartSeries megabytes
     */
    public function __construct(
        public array $owners,
        public array $categories,
        /** Objects whose owning project no longer exists, as a row of its own. */
        public null|StorageProjectRow $unattributed,
        public int $totalFiles,
        public int $totalSize,
        public int $orphanFiles,
        public int $orphanSize,
        /** Objects whose owning project no longer exists — un-billable leftovers. */
        public int $unattributedFiles,
        public int $unattributedSize,
        public null|DateTimeImmutable $lastScannedAt,
        public array $chartLabels,
        public array $chartSeries,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->totalFiles === 0;
    }

    public function clientCount(): int
    {
        return count($this->owners);
    }

    public function orphanShare(): float
    {
        return $this->totalSize === 0 ? 0.0 : $this->orphanSize / $this->totalSize * 100;
    }
}
