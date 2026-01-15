<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Meal;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Meal;
use WBoost\Web\Entity\MealVariant;
use WBoost\Web\Exceptions\DietNotFound;
use WBoost\Web\Exceptions\DishTypeNotFound;
use WBoost\Web\Exceptions\MealNotFound;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\FormData\MealVariantFormData;
use WBoost\Web\Message\Meal\AddMeal;
use WBoost\Web\Repository\DietRepository;
use WBoost\Web\Repository\DishTypeRepository;
use WBoost\Web\Repository\MealRepository;
use WBoost\Web\Repository\ProjectRepository;

#[AsMessageHandler]
readonly final class AddMealHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private DishTypeRepository $dishTypeRepository,
        private DietRepository $dietRepository,
        private MealRepository $mealRepository,
    ) {
    }

    /**
     * @throws ProjectNotFound
     * @throws DishTypeNotFound
     * @throws DietNotFound
     * @throws MealNotFound
     */
    public function __invoke(AddMeal $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $dishType = $this->dishTypeRepository->get($message->dishTypeId);
        $diet = $message->dietId !== null ? $this->dietRepository->get($message->dietId) : null;

        $meal = new Meal(
            $message->mealId,
            $project,
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

        $this->mealRepository->add($meal);
    }
}
