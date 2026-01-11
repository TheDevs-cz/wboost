<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenu;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;
use WBoost\Web\Message\WeeklyMenu\EditWeeklyMenuMealVariant;
use WBoost\Web\Repository\WeeklyMenuMealVariantRepository;

#[AsMessageHandler]
readonly final class EditWeeklyMenuMealVariantHandler
{
    public function __construct(
        private WeeklyMenuMealVariantRepository $variantRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function __invoke(EditWeeklyMenuMealVariant $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        $variant->edit($message->name);
        $variant->meal->menuDay->weeklyMenu->markUpdated();
    }
}
