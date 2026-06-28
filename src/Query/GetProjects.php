<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Project;

readonly final class GetProjects
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string>
     */
    public function sharedWithUser(UuidInterface $userId): array
    {
        /**
         * @var array<string> $rows
         */
        $rows = $this->entityManager->getConnection()
            ->executeQuery(
                'SELECT project_id FROM project_share WHERE user_id = :userId',
                ['userId' => $userId->toString()],
            )
            ->fetchFirstColumn();

        return $rows;
    }

    /**
     * Every project (admin view). Fetch-joins shares (kills the per-card
     * is_granted N+1) and the owner (for the owner label).
     *
     * @return array<Project>
     */
    public function all(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->from(Project::class, 'p')
            ->select('p', 'sh', 'o')
            ->leftJoin('p.shares', 'sh')
            ->leftJoin('p.owner', 'o')
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<Project>
     */
    public function allForUser(UuidInterface $userId): array
    {
        $sharedProjects = $this->sharedWithUser($userId);

        // Fetch-join the shares so the per-card `is_granted` check doesn't fire
        // one query per project.
        return $this->entityManager->createQueryBuilder()
            ->from(Project::class, 'p')
            ->select('p', 'sh')
            ->leftJoin('p.shares', 'sh')
            ->where('p.owner = :userId')
            ->orWhere('p.id IN (:sharedProjects)')
            ->setParameter('userId', $userId->toString())
            ->setParameter('sharedProjects', $sharedProjects)
            ->getQuery()
            ->getResult();
    }
}
