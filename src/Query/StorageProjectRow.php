<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

readonly final class StorageProjectRow
{
    /**
     * @param array<string, int> $sizesByCategory bytes keyed by StorageCategory value
     */
    public function __construct(
        public string $projectId,
        public string $projectName,
        public int $fileCount,
        public int $totalSize,
        public int $orphanCount,
        public int $orphanSize,
        public array $sizesByCategory = [],
    ) {
    }

    /** Bytes this project holds in one file type — 0 when it has none. */
    public function sizeInCategory(string $category): int
    {
        return $this->sizesByCategory[$category] ?? 0;
    }

    /** Share of this project's bytes that nothing references any more. */
    public function orphanShare(): float
    {
        return $this->totalSize === 0 ? 0.0 : $this->orphanSize / $this->totalSize * 100;
    }
}
