<?php

declare(strict_types=1);

namespace WBoost\Web\Message\WeeklyMenuPlanner;

use Ramsey\Uuid\UuidInterface;

readonly final class ReorderMeals
{
    /**
     * @param array<UuidInterface> $mealIds
     */
    public function __construct(
        public UuidInterface $variantId,
        public array $mealIds,
    ) {
    }
}
