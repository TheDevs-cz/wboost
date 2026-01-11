<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenu;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;
use WBoost\Web\Message\WeeklyMenu\DeleteWeeklyMenu;
use WBoost\Web\Repository\WeeklyMenuRepository;

#[AsMessageHandler]
readonly final class DeleteWeeklyMenuHandler
{
    public function __construct(
        private WeeklyMenuRepository $weeklyMenuRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function __invoke(DeleteWeeklyMenu $message): void
    {
        $menu = $this->weeklyMenuRepository->get($message->menuId);

        $this->weeklyMenuRepository->remove($menu);
    }
}
