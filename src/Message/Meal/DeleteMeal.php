<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Meal;

use Ramsey\Uuid\UuidInterface;

readonly final class DeleteMeal
{
    public function __construct(
        public UuidInterface $mealId,
    ) {
    }
}
