<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Meal;

use Ramsey\Uuid\UuidInterface;
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
        $diets = array_map(fn(UuidInterface $id) => $this->dietRepository->get($id), $message->dietIds);

        $meal = new Meal(
            $message->mealId,
            $project,
            $message->mealType,
            $dishType,
            $message->name,
            $message->internalName,
            $message->energyValue,
            $message->fats,
            $message->carbohydrates,
            $message->proteins,
        );
        $meal->setDiets($diets);

        $position = 0;
        foreach ($message->variants as $variantData) {
            $isManual = $variantData['mode'] === MealVariantFormData::MODE_MANUAL;

            $variantDiets = [];
            if ($isManual) {
                $variantDiets = array_map(fn(UuidInterface $id) => $this->dietRepository->get($id), $variantData['dietIds']);
            }

            $referenceMeal = null;
            if (!$isManual && $variantData['referenceMealId'] !== null) {
                $referenceMeal = $this->mealRepository->get($variantData['referenceMealId']);
            }

            $variant = new MealVariant(
                $variantData['id'],
                $meal,
                $isManual ? $variantData['name'] : null,
                $position++,
                $referenceMeal,
                $isManual ? $variantData['energyValue'] : null,
                $isManual ? $variantData['fats'] : null,
                $isManual ? $variantData['carbohydrates'] : null,
                $isManual ? $variantData['proteins'] : null,
            );
            $variant->setDiets($variantDiets);
            $meal->addVariant($variant);
        }

        $this->mealRepository->add($meal);
    }
}
