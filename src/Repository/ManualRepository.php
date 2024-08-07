<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Exceptions\ManualNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class ManualRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ManualNotFound
     */
    public function get(UuidInterface $manualId): Manual
    {
        $manual = $this->entityManager->find(Manual::class, $manualId);

        if ($manual instanceof Manual) {
            return $manual;
        }

        throw new ManualNotFound();
    }

    public function add(Manual $manual): void
    {
        $this->entityManager->persist($manual);
    }

    public function remove(Manual $manual): void
    {
        $this->entityManager->remove($manual);
    }
}
