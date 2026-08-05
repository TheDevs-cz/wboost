<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Doctrine\UuidType;
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

    /**
     * Records that the token was just used.
     *
     * Deliberately a **DQL UPDATE, not a `flush()`**. The only caller is
     * {@see \WBoost\Web\Mcp\Security\McpTokenAuthenticator}, which runs on
     * `kernel.request` — long before the request has decided what it wants to
     * persist. `flush()` there would commit the WHOLE unit of work as a side
     * effect of authenticating, so a request that fails halfway could still
     * leave partial writes behind. One statement in its own implicit
     * transaction cannot: either this column moved or it did not, and a command
     * handler rolling back its own `doctrine_transaction` later has no effect
     * on it.
     *
     * The in-memory entity is kept in step with `markUsed()` so anything
     * reading it in the same request sees the truth; should something else
     * flush afterwards it merely re-writes the identical value.
     */
    public function touchLastUsed(McpAccessToken $token, DateTimeImmutable $now): void
    {
        $token->markUsed($now);

        $this->entityManager->createQueryBuilder()
            ->update(McpAccessToken::class, 't')
            ->set('t.lastUsedAt', ':now')
            ->where('t.id = :id')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $token->id, UuidType::NAME)
            ->getQuery()
            ->execute();
    }
}
