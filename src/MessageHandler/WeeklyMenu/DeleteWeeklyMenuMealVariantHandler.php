<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenu;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;
use WBoost\Web\Message\WeeklyMenu\DeleteWeeklyMenuMealVariant;
use WBoost\Web\Repository\WeeklyMenuMealVariantRepository;

#[AsMessageHandler]
readonly final class DeleteWeeklyMenuMealVariantHandler
{
    public function __construct(
        private WeeklyMenuMealVariantRepository $variantRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function __invoke(DeleteWeeklyMenuMealVariant $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        if (!$variant->meal->canRemoveVariant()) {
            return;
        }

        $variant->meal->removeVariant($variant);
        $this->variantRepository->remove($variant);
    }
}
