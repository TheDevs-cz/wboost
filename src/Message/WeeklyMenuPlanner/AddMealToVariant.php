<?php

declare(strict_types=1);

namespace WBoost\Web\Message\WeeklyMenuPlanner;

use Ramsey\Uuid\UuidInterface;

readonly final class AddMealToVariant
{
    public function __construct(
        public UuidInterface $variantId,
        public UuidInterface $variantMealId,
        public UuidInterface $mealId,
    ) {
    }
}
