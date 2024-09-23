<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\ManualMockupPage;

readonly final class GetManualMockupPages
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<ManualMockupPage>
     */
    public function allForManual(UuidInterface $manualId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->from(ManualMockupPage::class, 'p')
            ->select('p')
            ->join('p.manual', 'm')
            ->where('m.id = :manualId')
            ->orderBy('p.position', 'ASC')
            ->setParameter('manualId', $manualId)
            ->getQuery()
            ->getResult();
    }
}
