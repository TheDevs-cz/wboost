<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Meal;
use WBoost\Web\Exceptions\MealNotFound;

readonly final class MealRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws MealNotFound
     */
    public function get(UuidInterface $mealId): Meal
    {
        $meal = $this->entityManager->find(Meal::class, $mealId);

        if ($meal instanceof Meal) {
            return $meal;
        }

        throw new MealNotFound();
    }

    public function add(Meal $meal): void
    {
        $this->entityManager->persist($meal);
    }

    public function remove(Meal $meal): void
    {
        $this->entityManager->remove($meal);
    }
}
