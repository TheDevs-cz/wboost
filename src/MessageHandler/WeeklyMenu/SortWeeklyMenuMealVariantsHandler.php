<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenu;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;
use WBoost\Web\Message\WeeklyMenu\SortWeeklyMenuMealVariants;
use WBoost\Web\Repository\WeeklyMenuMealRepository;
use WBoost\Web\Repository\WeeklyMenuMealVariantRepository;

#[AsMessageHandler]
readonly final class SortWeeklyMenuMealVariantsHandler
{
    public function __construct(
        private WeeklyMenuMealRepository $mealRepository,
        private WeeklyMenuMealVariantRepository $variantRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function __invoke(SortWeeklyMenuMealVariants $message): void
    {
        $this->mealRepository->get($message->mealId);

        foreach ($message->sortedIds as $position => $variantId) {
            $variant = $this->variantRepository->get($variantId);
            $variant->sortOrder = $position;
        }
    }
}
