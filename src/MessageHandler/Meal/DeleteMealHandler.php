<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Meal;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\MealNotFound;
use WBoost\Web\Message\Meal\DeleteMeal;
use WBoost\Web\Repository\MealRepository;
use WBoost\Web\Repository\MealVariantRepository;
use WBoost\Web\Repository\WeeklyMenuCourseVariantMealRepository;

#[AsMessageHandler]
readonly final class DeleteMealHandler
{
    public function __construct(
        private MealRepository $mealRepository,
        private WeeklyMenuCourseVariantMealRepository $weeklyMenuCourseVariantMealRepository,
        private MealVariantRepository $mealVariantRepository,
    ) {
    }

    /**
     * @throws MealNotFound
     */
    public function __invoke(DeleteMeal $message): void
    {
        $meal = $this->mealRepository->get($message->mealId);

        // Remove all weekly menu planner entries referencing this meal
        foreach ($this->weeklyMenuCourseVariantMealRepository->findByMeal($meal) as $variantMeal) {
            $variantMeal->courseVariant->removeMeal($variantMeal);
            $this->weeklyMenuCourseVariantMealRepository->remove($variantMeal);
        }

        // Remove all MealVariants in other meals that reference this meal
        foreach ($this->mealVariantRepository->findByReferenceMeal($meal) as $variant) {
            $variant->meal->removeVariant($variant);
        }

        $this->mealRepository->remove($meal);
    }
}
