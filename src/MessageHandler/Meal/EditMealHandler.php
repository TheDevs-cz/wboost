<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Meal;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\MealVariant;
use WBoost\Web\Exceptions\DietNotFound;
use WBoost\Web\Exceptions\DishTypeNotFound;
use WBoost\Web\Exceptions\MealNotFound;
use WBoost\Web\FormData\MealVariantFormData;
use WBoost\Web\Message\Meal\EditMeal;
use WBoost\Web\Repository\DietRepository;
use WBoost\Web\Repository\DishTypeRepository;
use WBoost\Web\Repository\MealRepository;

#[AsMessageHandler]
readonly final class EditMealHandler
{
    public function __construct(
        private MealRepository $mealRepository,
        private DishTypeRepository $dishTypeRepository,
        private DietRepository $dietRepository,
    ) {
    }

    /**
     * @throws MealNotFound
     * @throws DishTypeNotFound
     * @throws DietNotFound
     */
    public function __invoke(EditMeal $message): void
    {
        $meal = $this->mealRepository->get($message->mealId);
        $dishType = $this->dishTypeRepository->get($message->dishTypeId);
        $diet = $message->dietId !== null ? $this->dietRepository->get($message->dietId) : null;

        $meal->edit(
            $message->mealType,
            $dishType,
            $message->name,
            $message->internalName,
            $diet,
            $message->energyValue,
            $message->fats,
            $message->carbohydrates,
            $message->proteins,
        );

        // Get new variant IDs from message
        $newVariantIds = array_map(
            fn(array $v) => $v['id']->toString(),
            $message->variants,
        );

        // Remove variants that are not in the new list
        foreach ($meal->variants() as $variant) {
            if (!in_array($variant->id->toString(), $newVariantIds, true)) {
                $meal->removeVariant($variant);
            }
        }

        // Add or update variants
        $position = 0;
        foreach ($message->variants as $variantData) {
            $isManual = $variantData['mode'] === MealVariantFormData::MODE_MANUAL;

            $variantDiet = null;
            if ($isManual && $variantData['dietId'] !== null) {
                $variantDiet = $this->dietRepository->get($variantData['dietId']);
            }

            $referenceMeal = null;
            if (!$isManual && $variantData['referenceMealId'] !== null) {
                $referenceMeal = $this->mealRepository->get($variantData['referenceMealId']);
            }

            // Check if variant already exists
            $existingVariant = null;
            foreach ($meal->variants() as $variant) {
                if ($variant->id->equals($variantData['id'])) {
                    $existingVariant = $variant;
                    break;
                }
            }

            if ($existingVariant !== null) {
                if (!$isManual && $referenceMeal !== null) {
                    $existingVariant->editReference($referenceMeal);
                } else {
                    assert($variantDiet !== null);
                    $existingVariant->editManual(
                        $variantData['name'],
                        $variantDiet,
                        $variantData['energyValue'],
                        $variantData['fats'],
                        $variantData['carbohydrates'],
                        $variantData['proteins'],
                    );
                }
                $existingVariant->sort($position++);
            } else {
                $variant = new MealVariant(
                    $variantData['id'],
                    $meal,
                    $isManual ? $variantData['name'] : null,
                    $variantDiet,
                    $position++,
                    $referenceMeal,
                    $isManual ? $variantData['energyValue'] : null,
                    $isManual ? $variantData['fats'] : null,
                    $isManual ? $variantData['carbohydrates'] : null,
                    $isManual ? $variantData['proteins'] : null,
                );
                $meal->addVariant($variant);
            }
        }
    }
}
