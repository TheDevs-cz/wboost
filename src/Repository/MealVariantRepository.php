<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use WBoost\Web\Entity\Meal;
use WBoost\Web\Entity\MealVariant;

readonly final class MealVariantRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<MealVariant>
     */
    public function findByReferenceMeal(Meal $meal): array
    {
        return $this->entityManager->createQuery(
            'SELECT mv FROM ' . MealVariant::class . ' mv JOIN mv.meal m WHERE mv.referenceMeal = :meal',
        )->setParameter('meal', $meal)->getResult();
    }
}
