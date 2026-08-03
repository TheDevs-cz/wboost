<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

readonly final class StorageFilesPage
{
    /**
     * @param list<StorageFileRow> $rows
     * @param list<array{id: string, name: string}> $projects every project present in the inventory, for the filter
     */
    public function __construct(
        public array $rows,
        public int $totalCount,
        public int $totalSize,
        public int $page,
        public int $perPage,
        public array $projects,
    ) {
    }

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->totalCount / $this->perPage));
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pageCount();
    }
}
