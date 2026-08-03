<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use DateTimeImmutable;

readonly final class StorageScanResult
{
    /**
     * @param list<string> $danglingReferences paths the DB points at that are not in the bucket
     */
    public function __construct(
        public int $fileCount,
        public int $totalSize,
        public int $orphanCount,
        public int $orphanSize,
        public array $danglingReferences,
        public DateTimeImmutable $scannedAt,
    ) {
    }
}
