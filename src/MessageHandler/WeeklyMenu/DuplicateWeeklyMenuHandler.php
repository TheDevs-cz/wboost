<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenu;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Entity\WeeklyMenuCourse;
use WBoost\Web\Entity\WeeklyMenuCourseVariant;
use WBoost\Web\Entity\WeeklyMenuCourseVariantMeal;
use WBoost\Web\Entity\WeeklyMenuDay;
use WBoost\Web\Entity\WeeklyMenuDayMealType;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;
use WBoost\Web\Message\WeeklyMenu\DuplicateWeeklyMenu;
use WBoost\Web\Repository\WeeklyMenuRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class DuplicateWeeklyMenuHandler
{
    public function __construct(
        private WeeklyMenuRepository $weeklyMenuRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function __invoke(DuplicateWeeklyMenu $message): void
    {
        $originalMenu = $this->weeklyMenuRepository->get($message->originalMenuId);

        $newMenu = new WeeklyMenu(
            $message->newMenuId,
            $originalMenu->project,
            $this->clock->now(),
            $originalMenu->name . ' (kopie)',
            $originalMenu->validFrom,
            $originalMenu->validTo,
            null,
            $originalMenu->createdBy,
            $originalMenu->approvedBy,
        );

        $this->weeklyMenuRepository->add($newMenu);

        foreach ($originalMenu->days() as $originalDay) {
            $newDay = new WeeklyMenuDay(
                $this->provideIdentity->next(),
                $newMenu,
                $originalDay->dayOfWeek,
                $originalDay->date,
            );

            $newMenu->addDay($newDay);

            $this->copyMealTypes($originalDay, $newDay);
        }
    }

    private function copyMealTypes(WeeklyMenuDay $originalDay, WeeklyMenuDay $newDay): void
    {
        foreach ($originalDay->mealTypes() as $originalMealType) {
            $newMealType = new WeeklyMenuDayMealType(
                $this->provideIdentity->next(),
                $newDay,
                $originalMealType->mealType,
                $originalMealType->position,
            );

            $newDay->addMealType($newMealType);

            $this->copyCourses($originalMealType, $newMealType);
        }
    }

    private function copyCourses(WeeklyMenuDayMealType $originalMealType, WeeklyMenuDayMealType $newMealType): void
    {
        foreach ($originalMealType->courses() as $originalCourse) {
            $newCourse = new WeeklyMenuCourse(
                $this->provideIdentity->next(),
                $newMealType,
                $originalCourse->position,
            );

            $newMealType->addCourse($newCourse);

            $this->copyVariants($originalCourse, $newCourse);
        }
    }

    private function copyVariants(WeeklyMenuCourse $originalCourse, WeeklyMenuCourse $newCourse): void
    {
        foreach ($originalCourse->variants() as $originalVariant) {
            $newVariant = new WeeklyMenuCourseVariant(
                $this->provideIdentity->next(),
                $newCourse,
                $originalVariant->name,
                $originalVariant->position,
            );

            $newCourse->addVariant($newVariant);

            $this->copyMeals($originalVariant, $newVariant);
        }
    }

    private function copyMeals(WeeklyMenuCourseVariant $originalVariant, WeeklyMenuCourseVariant $newVariant): void
    {
        foreach ($originalVariant->meals() as $originalMeal) {
            $newMeal = new WeeklyMenuCourseVariantMeal(
                $this->provideIdentity->next(),
                $newVariant,
                $originalMeal->meal,
                $originalMeal->position,
            );

            $newVariant->addMeal($newMeal);
        }
    }
}
