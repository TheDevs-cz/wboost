<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenuPlanner;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuCourseVariantNotFound;
use WBoost\Web\Message\WeeklyMenuPlanner\ReorderMeals;
use WBoost\Web\Repository\WeeklyMenuCourseVariantRepository;

#[AsMessageHandler]
readonly final class ReorderMealsHandler
{
    public function __construct(
        private WeeklyMenuCourseVariantRepository $weeklyMenuCourseVariantRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuCourseVariantNotFound
     */
    public function __invoke(ReorderMeals $message): void
    {
        $variant = $this->weeklyMenuCourseVariantRepository->get($message->variantId);

        $meals = $variant->meals();
        $mealIds = array_map(
            static fn($id) => $id->toString(),
            $message->mealIds,
        );

        foreach ($meals as $meal) {
            $position = array_search($meal->id->toString(), $mealIds, true);
            if ($position !== false) {
                $meal->sort((int) $position);
            }
        }
    }
}
