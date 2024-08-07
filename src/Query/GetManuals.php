<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Exceptions\ProjectNotFound;

readonly final class GetManuals
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function oneForUser(UuidInterface $userId, UuidInterface $manualId): Manual
    {
        try {
            $row = $this->entityManager->createQueryBuilder()
                ->from(Manual::class, 'm')
                ->join('m.project', 'p')
                ->select('m', 'p')
                ->where('p.owner = :userId')
                ->setParameter('userId', $userId->toString())
                ->andWhere('m.id = :manualId')
                ->setParameter('manualId', $manualId->toString())
                ->getQuery()
                ->getSingleResult();

            assert($row instanceof Manual);
            return $row;
        } catch (NoResultException) {
            throw new ProjectNotFound();
        }
    }

    /**
     * @return array<Manual>
     */
    public function allForUser(UuidInterface $userId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->from(Manual::class, 'm')
            ->select('m')
            ->join('m.project', 'p')
            ->where('p.owner = :userId')
            ->setParameter('userId', $userId->toString())
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<Manual>
     */
    public function allForProject(UuidInterface $projectId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->from(Manual::class, 'm')
            ->select('m')
            ->join('m.project', 'p')
            ->where('p.id = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->getQuery()
            ->getResult();
    }
}
