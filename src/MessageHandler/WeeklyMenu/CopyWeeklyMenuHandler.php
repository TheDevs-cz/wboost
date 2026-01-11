<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenu;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;
use WBoost\Web\Message\WeeklyMenu\CopyWeeklyMenu;
use WBoost\Web\Repository\WeeklyMenuRepository;
use WBoost\Web\Services\WeeklyMenuFactory;

#[AsMessageHandler]
readonly final class CopyWeeklyMenuHandler
{
    public function __construct(
        private WeeklyMenuRepository $weeklyMenuRepository,
        private WeeklyMenuFactory $weeklyMenuFactory,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function __invoke(CopyWeeklyMenu $message): void
    {
        $originalMenu = $this->weeklyMenuRepository->get($message->originalMenuId);

        $newMenu = $this->weeklyMenuFactory->duplicate(
            $originalMenu,
            $message->validFrom,
            $message->validTo,
        );

        $this->weeklyMenuRepository->add($newMenu);
    }
}
