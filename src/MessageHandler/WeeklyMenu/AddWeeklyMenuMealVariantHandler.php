<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenu;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\WeeklyMenuMealVariant;
use WBoost\Web\Entity\WeeklyMenuMealVariantDietVersion;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;
use WBoost\Web\Message\WeeklyMenu\AddWeeklyMenuMealVariant;
use WBoost\Web\Repository\WeeklyMenuMealRepository;
use WBoost\Web\Repository\WeeklyMenuMealVariantRepository;
use WBoost\Web\Repository\WeeklyMenuDietVersionRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class AddWeeklyMenuMealVariantHandler
{
    public function __construct(
        private WeeklyMenuMealRepository $mealRepository,
        private WeeklyMenuMealVariantRepository $variantRepository,
        private WeeklyMenuDietVersionRepository $dietVersionRepository,
        private ProvideIdentity $provideIdentity,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function __invoke(AddWeeklyMenuMealVariant $message): void
    {
        $meal = $this->mealRepository->get($message->mealId);

        if (!$meal->canAddVariant()) {
            return;
        }

        $variant = new WeeklyMenuMealVariant(
            $message->variantId,
            $meal,
            $meal->nextVariantNumber(),
            null,
            $meal->nextSortOrder(),
        );

        $meal->addVariant($variant);
        $this->variantRepository->add($variant);

        $dietVersion = new WeeklyMenuMealVariantDietVersion(
            $this->provideIdentity->next(),
            $variant,
            null,
            null,
            0,
        );

        $variant->addDietVersion($dietVersion);
        $this->dietVersionRepository->add($dietVersion);
    }
}
