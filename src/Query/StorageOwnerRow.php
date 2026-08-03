<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

readonly final class StorageOwnerRow
{
    /**
     * @param list<StorageProjectRow> $projects
     */
    public function __construct(
        public string $ownerId,
        public string $ownerEmail,
        public int $fileCount,
        public int $totalSize,
        public int $orphanCount,
        public int $orphanSize,
        public array $projects,
    ) {
    }

    public function orphanShare(): float
    {
        return $this->totalSize === 0 ? 0.0 : $this->orphanSize / $this->totalSize * 100;
    }
}
