<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\WeeklyMenuMeal;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;

readonly final class WeeklyMenuMealRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function get(UuidInterface $mealId): WeeklyMenuMeal
    {
        $meal = $this->entityManager->find(WeeklyMenuMeal::class, $mealId);

        if ($meal instanceof WeeklyMenuMeal) {
            return $meal;
        }

        throw new WeeklyMenuNotFound();
    }
}
