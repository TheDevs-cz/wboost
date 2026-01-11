<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Entity\WeeklyMenuDay;
use WBoost\Web\Entity\WeeklyMenuMeal;
use WBoost\Web\Entity\WeeklyMenuMealVariant;
use WBoost\Web\Entity\WeeklyMenuMealVariantDietVersion;
use WBoost\Web\Value\WeeklyMenuMealType;

readonly class WeeklyMenuFactory
{
    public function __construct(
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
    ) {
    }

    public function create(
        Project $project,
        UuidInterface $menuId,
        string $name,
        \DateTimeImmutable $validFrom,
        \DateTimeImmutable $validTo,
        null|string $createdBy = null,
        null|string $approvedBy = null,
    ): WeeklyMenu {
        $menu = new WeeklyMenu(
            $menuId,
            $project,
            $this->clock->now(),
            $name,
            $validFrom,
            $validTo,
            null,
            $createdBy,
            $approvedBy,
        );

        for ($dayOfWeek = 1; $dayOfWeek <= 7; $dayOfWeek++) {
            $day = new WeeklyMenuDay(
                $this->provideIdentity->next(),
                $menu,
                $dayOfWeek,
            );
            $menu->addDay($day);

            foreach (WeeklyMenuMealType::cases() as $mealType) {
                $meal = new WeeklyMenuMeal(
                    $this->provideIdentity->next(),
                    $day,
                    $mealType,
                    $mealType->sortOrder(),
                );
                $day->addMeal($meal);

                $variant = new WeeklyMenuMealVariant(
                    $this->provideIdentity->next(),
                    $meal,
                    1,
                    null,
                    0,
                );
                $meal->addVariant($variant);

                $dietVersion = new WeeklyMenuMealVariantDietVersion(
                    $this->provideIdentity->next(),
                    $variant,
                    null,
                    null,
                    0,
                );
                $variant->addDietVersion($dietVersion);
            }
        }

        return $menu;
    }

    public function duplicate(
        WeeklyMenu $original,
        \DateTimeImmutable $newValidFrom,
        \DateTimeImmutable $newValidTo,
    ): WeeklyMenu {
        $menu = new WeeklyMenu(
            $this->provideIdentity->next(),
            $original->project,
            $this->clock->now(),
            $original->name . ' (kopie)',
            $newValidFrom,
            $newValidTo,
            null,
            $original->createdBy,
            $original->approvedBy,
        );

        foreach ($original->days() as $originalDay) {
            $day = new WeeklyMenuDay(
                $this->provideIdentity->next(),
                $menu,
                $originalDay->dayOfWeek,
                $originalDay->date,
            );
            $menu->addDay($day);

            foreach ($originalDay->meals() as $originalMeal) {
                $meal = new WeeklyMenuMeal(
                    $this->provideIdentity->next(),
                    $day,
                    $originalMeal->type,
                    $originalMeal->sortOrder,
                );
                $day->addMeal($meal);

                foreach ($originalMeal->variants() as $originalVariant) {
                    $variant = new WeeklyMenuMealVariant(
                        $this->provideIdentity->next(),
                        $meal,
                        $originalVariant->variantNumber,
                        $originalVariant->name,
                        $originalVariant->sortOrder,
                    );
                    $meal->addVariant($variant);

                    foreach ($originalVariant->dietVersions() as $originalDietVersion) {
                        $dietVersion = new WeeklyMenuMealVariantDietVersion(
                            $this->provideIdentity->next(),
                            $variant,
                            $originalDietVersion->dietCodes,
                            $originalDietVersion->items,
                            $originalDietVersion->sortOrder,
                        );
                        $variant->addDietVersion($dietVersion);
                    }
                }
            }
        }

        return $menu;
    }
}
