<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\SocialNetworkTemplate;

readonly final class GetSocialNetworks
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
            ->from(SocialNetworkTemplate::class, 's')
            ->select('s')
            ->join('s.project', 'p')
            ->where('p.id = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->getQuery()
            ->getResult();
    }
}
