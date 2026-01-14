<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenuPlanner;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuDayNotFound;
use WBoost\Web\Message\WeeklyMenuPlanner\RemoveDay;
use WBoost\Web\Repository\WeeklyMenuDayRepository;

#[AsMessageHandler]
readonly final class RemoveDayHandler
{
    public function __construct(
        private WeeklyMenuDayRepository $weeklyMenuDayRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuDayNotFound
     */
    public function __invoke(RemoveDay $message): void
    {
        $day = $this->weeklyMenuDayRepository->get($message->dayId);
        $day->weeklyMenu->removeDay($day);
        $this->weeklyMenuDayRepository->remove($day);
    }
}
