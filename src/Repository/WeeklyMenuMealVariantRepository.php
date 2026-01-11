<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\WeeklyMenuMealVariant;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;

readonly final class WeeklyMenuMealVariantRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function get(UuidInterface $variantId): WeeklyMenuMealVariant
    {
        $variant = $this->entityManager->find(WeeklyMenuMealVariant::class, $variantId);

        if ($variant instanceof WeeklyMenuMealVariant) {
            return $variant;
        }

        throw new WeeklyMenuNotFound();
    }

    public function add(WeeklyMenuMealVariant $variant): void
    {
        $this->entityManager->persist($variant);
    }

    public function remove(WeeklyMenuMealVariant $variant): void
    {
        $this->entityManager->remove($variant);
    }
}
