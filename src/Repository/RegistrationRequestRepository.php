<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\RegistrationRequest;
use WBoost\Web\Exceptions\RegistrationRequestNotFound;
use WBoost\Web\Value\RegistrationRequestStatus;

readonly final class RegistrationRequestRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(RegistrationRequest $request): void
    {
        $this->entityManager->persist($request);
    }

    /**
     * @throws RegistrationRequestNotFound
     */
    public function getById(UuidInterface $id): RegistrationRequest
    {
        $request = $this->entityManager->find(RegistrationRequest::class, $id);

        if ($request instanceof RegistrationRequest) {
            return $request;
        }

        throw new RegistrationRequestNotFound();
    }

    public function findPendingByEmail(string $email): null|RegistrationRequest
    {
        $request = $this->entityManager->createQueryBuilder()
            ->from(RegistrationRequest::class, 'r')
            ->select('r')
            ->where('r.email = :email')
            ->andWhere('r.status = :pending')
            ->setParameter('email', $email)
            ->setParameter('pending', RegistrationRequestStatus::Pending->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        assert($request instanceof RegistrationRequest || $request === null);

        return $request;
    }

    /**
     * @return list<RegistrationRequest>
     */
    public function allPendingFirst(): array
    {
        $requests = $this->entityManager->createQueryBuilder()
            ->from(RegistrationRequest::class, 'r')
            ->select('r')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // PHP 8 usort is stable, so the createdAt DESC order is preserved within
        // each status group.
        usort($requests, static function (RegistrationRequest $a, RegistrationRequest $b): int {
            $aPending = $a->status === RegistrationRequestStatus::Pending ? 0 : 1;
            $bPending = $b->status === RegistrationRequestStatus::Pending ? 0 : 1;

            return $aPending <=> $bPending;
        });

        return $requests;
    }
}
