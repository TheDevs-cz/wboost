<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\WeeklyMenuCourseVariant;
use WBoost\Web\Exceptions\WeeklyMenuCourseVariantNotFound;

readonly final class WeeklyMenuCourseVariantRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws WeeklyMenuCourseVariantNotFound
     */
    public function get(UuidInterface $variantId): WeeklyMenuCourseVariant
    {
        $variant = $this->entityManager->find(WeeklyMenuCourseVariant::class, $variantId);

        if ($variant instanceof WeeklyMenuCourseVariant) {
            return $variant;
        }

        throw new WeeklyMenuCourseVariantNotFound();
    }

    public function add(WeeklyMenuCourseVariant $variant): void
    {
        $this->entityManager->persist($variant);
    }

    public function remove(WeeklyMenuCourseVariant $variant): void
    {
        $this->entityManager->remove($variant);
    }
}
