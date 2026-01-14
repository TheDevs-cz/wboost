<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Diet;

readonly final class GetDiets
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<Diet>
     */
    public function allForProject(UuidInterface $projectId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->from(Diet::class, 'diet')
            ->select('diet')
            ->join('diet.project', 'project')
            ->where('project.id = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->orderBy('diet.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
