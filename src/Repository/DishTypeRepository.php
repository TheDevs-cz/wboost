<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\DishType;
use WBoost\Web\Exceptions\DishTypeNotFound;

readonly final class DishTypeRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws DishTypeNotFound
     */
    public function get(UuidInterface $dishTypeId): DishType
    {
        $dishType = $this->entityManager->find(DishType::class, $dishTypeId);

        if ($dishType instanceof DishType) {
            return $dishType;
        }

        throw new DishTypeNotFound();
    }

    public function add(DishType $dishType): void
    {
        $this->entityManager->persist($dishType);
    }

    public function remove(DishType $dishType): void
    {
        $this->entityManager->remove($dishType);
    }
}
