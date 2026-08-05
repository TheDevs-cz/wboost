<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use WBoost\Web\Entity\McpAccessToken;

readonly final class McpAccessTokenRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(McpAccessToken $token): void
    {
        $this->entityManager->persist($token);
    }

    /**
     * The authentication lookup: an exact match on the sha256 of the presented
     * wire token that is neither revoked nor expired.
     *
     * `$now` is a parameter rather than an injected clock so the repository
     * stays a plain query object — the caller already holds the request-time
     * `ClockInterface::now()`, and passing it keeps one instant across the
     * whole authentication (lookup + `markUsed()`).
     */
    public function findActiveByHash(string $tokenHash, DateTimeImmutable $now): null|McpAccessToken
    {
        $token = $this->entityManager->createQueryBuilder()
            ->from(McpAccessToken::class, 't')
            ->select('t')
            ->where('t.tokenHash = :tokenHash')
            ->andWhere('t.revokedAt IS NULL')
            // Explicit parentheses: andWhere() does not wrap a raw string, so
            // without them the OR would swallow the conditions above it.
            ->andWhere('(t.expiresAt IS NULL OR t.expiresAt > :now)')
            ->setParameter('tokenHash', $tokenHash)
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();

        assert($token instanceof McpAccessToken || $token === null);

        return $token;
    }
}
