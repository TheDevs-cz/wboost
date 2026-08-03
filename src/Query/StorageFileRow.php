<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use DateTimeImmutable;
use WBoost\Web\Value\StorageCategory;

readonly final class StorageFileRow
{
    public function __construct(
        public string $path,
        public int $size,
        public null|DateTimeImmutable $lastModifiedAt,
        public StorageCategory $category,
        public null|string $referencedBy,
        public int $referenceCount,
        public null|string $projectId,
        public null|string $projectName,
        public null|string $ownerEmail,
        public bool $orphaned,
    ) {
    }

    public function fileName(): string
    {
        $position = strrpos($this->path, '/');

        return $position === false ? $this->path : substr($this->path, $position + 1);
    }

    /** A key several DB rows point at — deleting it would break more than one. */
    public function isShared(): bool
    {
        return $this->referenceCount > 1;
    }
}
