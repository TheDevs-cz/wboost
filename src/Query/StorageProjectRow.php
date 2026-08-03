<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

readonly final class StorageProjectRow
{
    public function __construct(
        public string $projectId,
        public string $projectName,
        public int $fileCount,
        public int $totalSize,
        public int $orphanCount,
        public int $orphanSize,
    ) {
    }

    /** Share of this project's bytes that nothing references any more. */
    public function orphanShare(): float
    {
        return $this->totalSize === 0 ? 0.0 : $this->orphanSize / $this->totalSize * 100;
    }
}
