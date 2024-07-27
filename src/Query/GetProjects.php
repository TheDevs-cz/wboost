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
     * @throws ProjectNotFound
     */
    public function oneForUser(UuidInterface $userId, UuidInterface $projectId): Project
    {
        try {
            $row = $this->entityManager->createQueryBuilder()
                ->from(Project::class, 'p')
                ->select('p')
                ->where('p.owner = :userId')
                ->setParameter('userId', $userId->toString())
                ->andWhere('p.id = :projectId')
                ->setParameter('projectId', $projectId->toString())
                ->getQuery()
                ->getSingleResult();

            assert($row instanceof Project);
            return $row;
        } catch (NoResultException) {
            throw new ProjectNotFound();
        }
    }

    /**
     * @return array<Project>
     */
    public function allForUser(UuidInterface $userId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->from(Project::class, 'p')
            ->select('p')
            ->where('p.owner = :userId')
            ->setParameter('userId', $userId->toString())
            ->getQuery()
            ->getResult();
    }
}
