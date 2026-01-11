<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\WeeklyMenuMealVariantDietVersion;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;

readonly final class WeeklyMenuDietVersionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function get(UuidInterface $dietVersionId): WeeklyMenuMealVariantDietVersion
    {
        $dietVersion = $this->entityManager->find(WeeklyMenuMealVariantDietVersion::class, $dietVersionId);

        if ($dietVersion instanceof WeeklyMenuMealVariantDietVersion) {
            return $dietVersion;
        }

        throw new WeeklyMenuNotFound();
    }

    public function add(WeeklyMenuMealVariantDietVersion $dietVersion): void
    {
        $this->entityManager->persist($dietVersion);
    }

    public function remove(WeeklyMenuMealVariantDietVersion $dietVersion): void
    {
        $this->entityManager->remove($dietVersion);
    }
}
