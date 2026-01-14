<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\DishType;

readonly final class GetDishTypes
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<DishType>
     */
    public function allForProject(UuidInterface $projectId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->from(DishType::class, 'dishType')
            ->select('dishType')
            ->join('dishType.project', 'project')
            ->where('project.id = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->orderBy('dishType.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countForProject(UuidInterface $projectId): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->from(DishType::class, 'dishType')
            ->select('COUNT(dishType.id)')
            ->join('dishType.project', 'project')
            ->where('project.id = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->getQuery()
            ->getSingleScalarResult();
    }
}
