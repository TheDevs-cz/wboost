<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenu;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;
use WBoost\Web\Message\WeeklyMenu\EditWeeklyMenuDietVersion;
use WBoost\Web\Repository\WeeklyMenuDietVersionRepository;

#[AsMessageHandler]
readonly final class EditWeeklyMenuDietVersionHandler
{
    public function __construct(
        private WeeklyMenuDietVersionRepository $dietVersionRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function __invoke(EditWeeklyMenuDietVersion $message): void
    {
        $dietVersion = $this->dietVersionRepository->get($message->dietVersionId);

        $dietVersion->edit($message->dietCodes, $message->items);
        $dietVersion->variant->meal->menuDay->weeklyMenu->markUpdated();
    }
}
