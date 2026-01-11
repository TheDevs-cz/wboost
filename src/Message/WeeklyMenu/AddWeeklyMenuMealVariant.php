<?php

declare(strict_types=1);

namespace WBoost\Web\Message\WeeklyMenu;

use Ramsey\Uuid\UuidInterface;

readonly final class AddWeeklyMenuMealVariant
{
    public function __construct(
        public UuidInterface $mealId,
        public UuidInterface $variantId,
    ) {
    }
}
