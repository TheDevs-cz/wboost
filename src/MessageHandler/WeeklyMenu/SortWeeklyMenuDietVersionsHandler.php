<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenu;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;
use WBoost\Web\Message\WeeklyMenu\SortWeeklyMenuDietVersions;
use WBoost\Web\Repository\WeeklyMenuDietVersionRepository;
use WBoost\Web\Repository\WeeklyMenuMealVariantRepository;

#[AsMessageHandler]
readonly final class SortWeeklyMenuDietVersionsHandler
{
    public function __construct(
        private WeeklyMenuMealVariantRepository $variantRepository,
        private WeeklyMenuDietVersionRepository $dietVersionRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function __invoke(SortWeeklyMenuDietVersions $message): void
    {
        $this->variantRepository->get($message->variantId);

        foreach ($message->sortedIds as $position => $dietVersionId) {
            $dietVersion = $this->dietVersionRepository->get($dietVersionId);
            $dietVersion->sortOrder = $position;
        }
    }
}
