<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenuPlanner;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\WeeklyMenuCourseVariantMeal;
use WBoost\Web\Exceptions\MealNotFound;
use WBoost\Web\Exceptions\WeeklyMenuCourseVariantNotFound;
use WBoost\Web\Message\WeeklyMenuPlanner\AddMealToVariant;
use WBoost\Web\Repository\MealRepository;
use WBoost\Web\Repository\WeeklyMenuCourseVariantMealRepository;
use WBoost\Web\Repository\WeeklyMenuCourseVariantRepository;

#[AsMessageHandler]
readonly final class AddMealToVariantHandler
{
    public function __construct(
        private WeeklyMenuCourseVariantRepository $weeklyMenuCourseVariantRepository,
        private MealRepository $mealRepository,
        private WeeklyMenuCourseVariantMealRepository $weeklyMenuCourseVariantMealRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuCourseVariantNotFound
     * @throws MealNotFound
     */
    public function __invoke(AddMealToVariant $message): void
    {
        $variant = $this->weeklyMenuCourseVariantRepository->get($message->variantId);
        $meal = $this->mealRepository->get($message->mealId);

        $position = count($variant->meals());

        $variantMeal = new WeeklyMenuCourseVariantMeal(
            $message->variantMealId,
            $variant,
            $meal,
            $position,
        );

        $variant->addMeal($variantMeal);
        $this->weeklyMenuCourseVariantMealRepository->add($variantMeal);
    }
}
