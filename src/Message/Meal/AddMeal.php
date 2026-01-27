<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Meal;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\WeeklyMenuMealType;

readonly final class AddMeal
{
    /**
     * @param array<UuidInterface> $dietIds
     * @param array<array{
     *     id: UuidInterface,
     *     mode: string,
     *     name: string,
     *     dietIds: array<UuidInterface>,
     *     referenceMealId: UuidInterface|null,
     *     energyValue: string|null,
     *     fats: string|null,
     *     carbohydrates: string|null,
     *     proteins: string|null
     * }> $variants
     */
    public function __construct(
        public UuidInterface $projectId,
        public UuidInterface $mealId,
        public WeeklyMenuMealType $mealType,
        public UuidInterface $dishTypeId,
        public string $name,
        public string $internalName,
        public array $dietIds = [],
        public null|string $energyValue = null,
        public null|string $fats = null,
        public null|string $carbohydrates = null,
        public null|string $proteins = null,
        public array $variants = [],
    ) {
    }
}
