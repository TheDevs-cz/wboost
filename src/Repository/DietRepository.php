<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Diet;
use WBoost\Web\Exceptions\DietNotFound;

readonly final class DietRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws DietNotFound
     */
    public function get(UuidInterface $dietId): Diet
    {
        $diet = $this->entityManager->find(Diet::class, $dietId);

        if ($diet instanceof Diet) {
            return $diet;
        }

        throw new DietNotFound();
    }

    public function add(Diet $diet): void
    {
        $this->entityManager->persist($diet);
    }

    public function remove(Diet $diet): void
    {
        $this->entityManager->remove($diet);
    }
}
