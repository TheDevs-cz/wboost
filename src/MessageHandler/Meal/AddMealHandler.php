<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Meal;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Meal;
use WBoost\Web\Entity\MealVariant;
use WBoost\Web\Exceptions\DietNotFound;
use WBoost\Web\Exceptions\DishTypeNotFound;
use WBoost\Web\Exceptions\ProjectNotFound;
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
        );

        $position = 0;
        foreach ($message->variants as $variantData) {
            $variantDiet = $this->dietRepository->get($variantData['dietId']);
            $variant = new MealVariant(
                $variantData['id'],
                $meal,
                $variantData['name'],
                $variantDiet,
                $position++,
            );
            $meal->addVariant($variant);
        }

        $this->mealRepository->add($meal);
    }
}
