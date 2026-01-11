<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenu;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;
use WBoost\Web\Message\WeeklyMenu\EditWeeklyMenu;
use WBoost\Web\Repository\WeeklyMenuRepository;

#[AsMessageHandler]
readonly final class EditWeeklyMenuHandler
{
    public function __construct(
        private WeeklyMenuRepository $weeklyMenuRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function __invoke(EditWeeklyMenu $message): void
    {
        $menu = $this->weeklyMenuRepository->get($message->menuId);

        $menu->edit(
            $message->name,
            $message->validFrom,
            $message->validTo,
            $message->createdBy,
            $message->approvedBy,
        );
    }
}
