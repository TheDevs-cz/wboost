<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\WeeklyMenuApprovalAuditLog;

readonly final class WeeklyMenuApprovalAuditLogRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(WeeklyMenuApprovalAuditLog $log): void
    {
        $this->entityManager->persist($log);
    }

    /**
     * @return array<WeeklyMenuApprovalAuditLog>
     */
    public function findByMenu(UuidInterface $menuId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('log')
            ->from(WeeklyMenuApprovalAuditLog::class, 'log')
            ->where('log.weeklyMenu = :menuId')
            ->setParameter('menuId', $menuId)
            ->orderBy('log.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
