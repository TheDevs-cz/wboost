<?php

declare(strict_types=1);

namespace WBoost\Web\Message\WeeklyMenu;

use Ramsey\Uuid\UuidInterface;

readonly final class SortWeeklyMenuMealVariants
{
    /**
     * @param array<UuidInterface> $sortedIds
     */
    public function __construct(
        public UuidInterface $mealId,
        public array $sortedIds,
    ) {
    }
}
