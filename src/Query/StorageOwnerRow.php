<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

readonly final class StorageOwnerRow
{
    /**
     * @param list<StorageProjectRow> $projects
     * @param array<string, int> $sizesByCategory bytes keyed by StorageCategory value
     */
    public function __construct(
        public string $ownerId,
        public string $ownerEmail,
        public int $fileCount,
        public int $totalSize,
        public int $orphanCount,
        public int $orphanSize,
        public array $projects,
        public array $sizesByCategory = [],
    ) {
    }

    /** Bytes this client holds in one file type — 0 when they have none. */
    public function sizeInCategory(string $category): int
    {
        return $this->sizesByCategory[$category] ?? 0;
    }

    public function orphanShare(): float
    {
        return $this->totalSize === 0 ? 0.0 : $this->orphanSize / $this->totalSize * 100;
    }
}
