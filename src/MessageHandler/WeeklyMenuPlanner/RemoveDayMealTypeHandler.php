<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenuPlanner;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuDayMealTypeNotFound;
use WBoost\Web\Message\WeeklyMenuPlanner\RemoveDayMealType;
use WBoost\Web\Repository\WeeklyMenuDayMealTypeRepository;

#[AsMessageHandler]
readonly final class RemoveDayMealTypeHandler
{
    public function __construct(
        private WeeklyMenuDayMealTypeRepository $weeklyMenuDayMealTypeRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuDayMealTypeNotFound
     */
    public function __invoke(RemoveDayMealType $message): void
    {
        $dayMealType = $this->weeklyMenuDayMealTypeRepository->get($message->dayMealTypeId);
        $dayMealType->weeklyMenuDay->removeMealType($dayMealType);
        $this->weeklyMenuDayMealTypeRepository->remove($dayMealType);
    }
}
