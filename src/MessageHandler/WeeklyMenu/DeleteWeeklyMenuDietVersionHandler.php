<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenu;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;
use WBoost\Web\Message\WeeklyMenu\DeleteWeeklyMenuDietVersion;
use WBoost\Web\Repository\WeeklyMenuDietVersionRepository;

#[AsMessageHandler]
readonly final class DeleteWeeklyMenuDietVersionHandler
{
    public function __construct(
        private WeeklyMenuDietVersionRepository $dietVersionRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function __invoke(DeleteWeeklyMenuDietVersion $message): void
    {
        $dietVersion = $this->dietVersionRepository->get($message->dietVersionId);

        if (!$dietVersion->variant->canRemoveDietVersion()) {
            return;
        }

        $dietVersion->variant->removeDietVersion($dietVersion);
        $this->dietVersionRepository->remove($dietVersion);
    }
}
