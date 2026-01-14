<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenuPlanner;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\WeeklyMenuDayMealType;
use WBoost\Web\Exceptions\WeeklyMenuDayNotFound;
use WBoost\Web\Message\WeeklyMenuPlanner\AddDayMealType;
use WBoost\Web\Repository\WeeklyMenuDayMealTypeRepository;
use WBoost\Web\Repository\WeeklyMenuDayRepository;

#[AsMessageHandler]
readonly final class AddDayMealTypeHandler
{
    public function __construct(
        private WeeklyMenuDayRepository $weeklyMenuDayRepository,
        private WeeklyMenuDayMealTypeRepository $weeklyMenuDayMealTypeRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuDayNotFound
     */
    public function __invoke(AddDayMealType $message): void
    {
        $day = $this->weeklyMenuDayRepository->get($message->weeklyMenuDayId);

        $existingMealTypes = $day->mealTypes();
        $newSortOrder = $message->mealType->sortOrder();

        // Calculate position based on chronological order (sortOrder)
        $position = 0;
        foreach ($existingMealTypes as $existingMealType) {
            if ($existingMealType->mealType->sortOrder() < $newSortOrder) {
                $position++;
            }
        }

        // Shift positions of meal types that come after the new one
        foreach ($existingMealTypes as $existingMealType) {
            if ($existingMealType->position >= $position) {
                $existingMealType->sort($existingMealType->position + 1);
            }
        }

        $dayMealType = new WeeklyMenuDayMealType(
            $message->dayMealTypeId,
            $day,
            $message->mealType,
            $position,
        );

        $day->addMealType($dayMealType);
        $this->weeklyMenuDayMealTypeRepository->add($dayMealType);
    }
}
