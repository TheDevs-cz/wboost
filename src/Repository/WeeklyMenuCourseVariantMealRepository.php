<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\WeeklyMenuCourseVariantMeal;
use WBoost\Web\Exceptions\WeeklyMenuCourseVariantMealNotFound;

readonly final class WeeklyMenuCourseVariantMealRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws WeeklyMenuCourseVariantMealNotFound
     */
    public function get(UuidInterface $variantMealId): WeeklyMenuCourseVariantMeal
    {
        $variantMeal = $this->entityManager->find(WeeklyMenuCourseVariantMeal::class, $variantMealId);

        if ($variantMeal instanceof WeeklyMenuCourseVariantMeal) {
            return $variantMeal;
        }

        throw new WeeklyMenuCourseVariantMealNotFound();
    }

    public function add(WeeklyMenuCourseVariantMeal $variantMeal): void
    {
        $this->entityManager->persist($variantMeal);
    }

    public function remove(WeeklyMenuCourseVariantMeal $variantMeal): void
    {
        $this->entityManager->remove($variantMeal);
    }
}
