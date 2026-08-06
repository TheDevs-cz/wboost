<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Doctrine\UuidType;
use WBoost\Web\Entity\OAuthClientApproval;
use WBoost\Web\Entity\User;

readonly final class OAuthClientApprovalRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(OAuthClientApproval $approval): void
    {
        $this->entityManager->persist($approval);
    }

    public function remove(OAuthClientApproval $approval): void
    {
        $this->entityManager->remove($approval);
    }

    /**
     * The (user, client) pair is unique, so this is a lookup and not a list.
     */
    public function findFor(User $user, string $clientIdentifier): null|OAuthClientApproval
    {
        $approval = $this->entityManager->createQueryBuilder()
            ->from(OAuthClientApproval::class, 'a')
            ->select('a')
            ->where('a.user = :user')
            ->andWhere('a.clientIdentifier = :clientIdentifier')
            ->setParameter('user', $user)
            ->setParameter('clientIdentifier', $clientIdentifier)
            ->getQuery()
            ->getOneOrNullResult();

        assert($approval instanceof OAuthClientApproval || $approval === null);

        return $approval;
    }

    /**
     * @return list<OAuthClientApproval>
     */
    public function listForUser(User $user): array
    {
        /** @var list<OAuthClientApproval> $approvals */
        $approvals = $this->entityManager->createQueryBuilder()
            ->from(OAuthClientApproval::class, 'a')
            ->select('a')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('a.approvedAt', 'DESC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $approvals;
    }

    /**
     * Records that a remembered approval just let an authorization through.
     *
     * A **DQL UPDATE, not a `flush()`**, for the same reason
     * {@see McpAccessTokenRepository::touchLastUsed()} is one: the only caller
     * is an event listener running inside the bundle's authorization
     * controller, long before the request has decided what it wants to persist.
     * Flushing there would commit the whole unit of work as a side effect of
     * resolving an authorization. One statement in its own implicit transaction
     * cannot do that, and the in-memory entity is kept in step so anything
     * reading it later in the request sees the truth.
     */
    public function touchLastUsed(OAuthClientApproval $approval, DateTimeImmutable $now): void
    {
        $approval->markUsed($now);

        $this->entityManager->createQueryBuilder()
            ->update(OAuthClientApproval::class, 'a')
            ->set('a.lastUsedAt', ':now')
            ->where('a.id = :id')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $approval->id, UuidType::NAME)
            ->getQuery()
            ->execute();
    }
}
