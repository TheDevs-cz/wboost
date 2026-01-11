<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenu;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\WeeklyMenu\AddWeeklyMenu;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\WeeklyMenuRepository;
use WBoost\Web\Services\WeeklyMenuFactory;

#[AsMessageHandler]
readonly final class AddWeeklyMenuHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private WeeklyMenuRepository $weeklyMenuRepository,
        private WeeklyMenuFactory $weeklyMenuFactory,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(AddWeeklyMenu $message): void
    {
        $project = $this->projectRepository->get($message->projectId);

        $menu = $this->weeklyMenuFactory->create(
            $project,
            $message->menuId,
            $message->name,
            $message->validFrom,
            $message->validTo,
            $message->createdBy,
            $message->approvedBy,
        );

        $this->weeklyMenuRepository->add($menu);
    }
}
