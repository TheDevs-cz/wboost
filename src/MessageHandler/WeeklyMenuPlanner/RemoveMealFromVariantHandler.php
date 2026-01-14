<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenuPlanner;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuCourseVariantMealNotFound;
use WBoost\Web\Message\WeeklyMenuPlanner\RemoveMealFromVariant;
use WBoost\Web\Repository\WeeklyMenuCourseVariantMealRepository;

#[AsMessageHandler]
readonly final class RemoveMealFromVariantHandler
{
    public function __construct(
        private WeeklyMenuCourseVariantMealRepository $weeklyMenuCourseVariantMealRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuCourseVariantMealNotFound
     */
    public function __invoke(RemoveMealFromVariant $message): void
    {
        $variantMeal = $this->weeklyMenuCourseVariantMealRepository->get($message->variantMealId);
        $variantMeal->courseVariant->removeMeal($variantMeal);
        $this->weeklyMenuCourseVariantMealRepository->remove($variantMeal);
    }
}
