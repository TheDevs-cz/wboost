<?php

declare(strict_types=1);

namespace WBoost\Web\Message\WeeklyMenuPlanner;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\WeeklyMenuMealType;

readonly final class AddDayMealType
{
    public function __construct(
        public UuidInterface $weeklyMenuDayId,
        public UuidInterface $dayMealTypeId,
        public WeeklyMenuMealType $mealType,
    ) {
    }
}
