<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Exceptions\AiScanNotFound;
use WBoost\Web\Value\AiScan\AiScanStatus;

readonly final class AiScanRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The row as it is NOW. Scans are changed by raw conditional updates
     * (see {@see AiScan}), so an instance already in the identity map is
     * refreshed instead of trusted.
     */
    public function get(UuidInterface $scanId): AiScan
    {
        $scan = $this->find($scanId);

        if ($scan === null) {
            throw new AiScanNotFound();
        }

        return $scan;
    }

    public function find(UuidInterface $scanId): null|AiScan
    {
        $scan = $this->entityManager->find(AiScan::class, $scanId);

        if (!$scan instanceof AiScan) {
            return null;
        }

        $this->entityManager->refresh($scan);

        return $scan;
    }

    public function findByRequest(UuidInterface $userId, string $requestId): null|AiScan
    {
        return $this->entityManager->getRepository(AiScan::class)->findOneBy([
            'requestedBy' => $userId->toString(),
            'requestId' => $requestId,
        ]);
    }

    public function add(AiScan $scan): void
    {
        $this->entityManager->persist($scan);
    }

    /**
     * A user's own scans that are still of interest to the shell: everything
     * in progress and whatever ended unused, newest first.
     *
     * @return list<AiScan>
     */
    public function mine(UuidInterface $userId, int $limit = 20): array
    {
        /** @var list<AiScan> $scans */
        $scans = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(AiScan::class, 's')
            ->where('s.requestedBy = :user')
            ->andWhere('s.status IN (:states)')
            ->setParameter('user', $userId->toString())
            ->setParameter('states', array_map(
                static fn (AiScanStatus $status): string => $status->value,
                [...AiScanStatus::inProgress(), ...AiScanStatus::endedUnused()],
            ))
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        foreach ($scans as $scan) {
            $this->entityManager->refresh($scan);
        }

        return $scans;
    }
}
