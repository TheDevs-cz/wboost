<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\SocialNetworkTemplate;

readonly final class GetSocialNetworkTemplates
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<SocialNetworkTemplate>
     */
    public function allForProject(UuidInterface $projectId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->from(SocialNetworkTemplate::class, 'template')
            ->select('template')
            ->join('template.project', 'project')
            ->where('project.id = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->orderBy('template.position')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<SocialNetworkTemplate>
     */
    public function withoutCategoryForProject(UuidInterface $projectId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->from(SocialNetworkTemplate::class, 'template')
            ->select('template')
            ->join('template.project', 'project')
            ->where('project.id = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->andWhere('template.category IS NULL')
            ->orderBy('template.position')
            ->getQuery()
            ->getResult();
    }
}
