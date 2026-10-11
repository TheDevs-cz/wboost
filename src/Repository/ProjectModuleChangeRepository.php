<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\ProjectModuleChange;

readonly final class ProjectModuleChangeRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(ProjectModuleChange $change): void
    {
        $this->entityManager->persist($change);
    }

    /**
     * @return list<ProjectModuleChange> newest first
     */
    public function latestForProject(UuidInterface $projectId, int $limit = 20): array
    {
        /** @var list<ProjectModuleChange> $changes */
        $changes = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(ProjectModuleChange::class, 'c')
            ->where('c.project = :projectId')
            ->setParameter('projectId', $projectId)
            ->orderBy('c.changedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $changes;
    }
}
