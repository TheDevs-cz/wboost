<?php

declare(strict_types=1);

namespace WBoost\Web\Message\WeeklyMenu;

use Ramsey\Uuid\UuidInterface;

readonly final class SortWeeklyMenuDietVersions
{
    /**
     * @param array<UuidInterface> $sortedIds
     */
    public function __construct(
        public UuidInterface $variantId,
        public array $sortedIds,
    ) {
    }
}
