<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use WBoost\Web\Value\StorageCategory;

readonly final class StorageCategoryRow
{
    public function __construct(
        public StorageCategory $category,
        public int $fileCount,
        public int $totalSize,
        public int $orphanCount,
        public int $orphanSize,
    ) {
    }
}
