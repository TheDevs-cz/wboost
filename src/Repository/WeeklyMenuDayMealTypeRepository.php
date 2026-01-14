<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\WeeklyMenuDayMealType;
use WBoost\Web\Exceptions\WeeklyMenuDayMealTypeNotFound;

readonly final class WeeklyMenuDayMealTypeRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws WeeklyMenuDayMealTypeNotFound
     */
    public function get(UuidInterface $dayMealTypeId): WeeklyMenuDayMealType
    {
        $dayMealType = $this->entityManager->find(WeeklyMenuDayMealType::class, $dayMealTypeId);

        if ($dayMealType instanceof WeeklyMenuDayMealType) {
            return $dayMealType;
        }

        throw new WeeklyMenuDayMealTypeNotFound();
    }

    public function add(WeeklyMenuDayMealType $dayMealType): void
    {
        $this->entityManager->persist($dayMealType);
    }

    public function remove(WeeklyMenuDayMealType $dayMealType): void
    {
        $this->entityManager->remove($dayMealType);
    }
}
