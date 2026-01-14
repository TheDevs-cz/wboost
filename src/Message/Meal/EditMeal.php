<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Meal;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\WeeklyMenuMealType;

readonly final class EditMeal
{
    /**
     * @param array<array{id: UuidInterface, name: string, dietId: UuidInterface}> $variants
     */
    public function __construct(
        public UuidInterface $mealId,
        public WeeklyMenuMealType $mealType,
        public UuidInterface $dishTypeId,
        public string $name,
        public string $internalName,
        public null|UuidInterface $dietId = null,
        public array $variants = [],
    ) {
    }
}
