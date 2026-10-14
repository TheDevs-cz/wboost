<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use WBoost\Web\Entity\LandingLead;

readonly final class LandingLeadRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(LandingLead $lead): void
    {
        $this->entityManager->persist($lead);
    }

    /**
     * @return list<LandingLead>
     */
    public function newestFirst(): array
    {
        /** @var list<LandingLead> */
        return $this->entityManager->createQueryBuilder()
            ->select('l')
            ->from(LandingLead::class, 'l')
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
