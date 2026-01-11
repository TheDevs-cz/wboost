<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenu;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\WeeklyMenuMealVariantDietVersion;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;
use WBoost\Web\Message\WeeklyMenu\AddWeeklyMenuDietVersion;
use WBoost\Web\Repository\WeeklyMenuDietVersionRepository;
use WBoost\Web\Repository\WeeklyMenuMealVariantRepository;

#[AsMessageHandler]
readonly final class AddWeeklyMenuDietVersionHandler
{
    public function __construct(
        private WeeklyMenuMealVariantRepository $variantRepository,
        private WeeklyMenuDietVersionRepository $dietVersionRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function __invoke(AddWeeklyMenuDietVersion $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        if (!$variant->canAddDietVersion()) {
            return;
        }

        $dietVersion = new WeeklyMenuMealVariantDietVersion(
            $message->dietVersionId,
            $variant,
            null,
            null,
            $variant->nextSortOrder(),
        );

        $variant->addDietVersion($dietVersion);
        $this->dietVersionRepository->add($dietVersion);
    }
}
