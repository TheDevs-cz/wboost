<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Meal;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\MealNotFound;
use WBoost\Web\Message\Meal\DeleteMeal;
use WBoost\Web\Repository\MealRepository;

#[AsMessageHandler]
readonly final class DeleteMealHandler
{
    public function __construct(
        private MealRepository $mealRepository,
    ) {
    }

    /**
     * @throws MealNotFound
     */
    public function __invoke(DeleteMeal $message): void
    {
        $meal = $this->mealRepository->get($message->mealId);
        $this->mealRepository->remove($meal);
    }
}
