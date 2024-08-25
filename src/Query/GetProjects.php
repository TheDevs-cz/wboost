<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\ProjectNotFound;

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
        $sql = <<<SQL
SELECT id
FROM project,
    jsonb_array_elements(sharing) AS elem
WHERE elem->>'userId' = :userId
SQL;

        /**
         * @var array<string> $rows
         */
        $rows = $this->entityManager->getConnection()
            ->executeQuery($sql, [
                'userId' => $userId->toString(),
            ])
            ->fetchFirstColumn();

        return $rows;
    }

    /**
     * @return array<Project>
     */
    public function allForUser(UuidInterface $userId): array
    {
        $sharedProjects = $this->sharedWithUser($userId);

        return $this->entityManager->createQueryBuilder()
            ->from(Project::class, 'p')
            ->select('p')
            ->where('p.owner = :userId')
            ->orWhere('p.id IN (:sharedProjects)')
            ->setParameter('userId', $userId->toString())
            ->setParameter('sharedProjects', $sharedProjects)
            ->getQuery()
            ->getResult();
    }
}
