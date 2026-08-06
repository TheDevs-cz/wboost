<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\McpAccessToken;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\McpAccessTokenNotFound;

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
     * @throws McpAccessTokenNotFound
     */
    public function get(UuidInterface $tokenId): McpAccessToken
    {
        $token = $this->entityManager->find(McpAccessToken::class, $tokenId);

        if ($token instanceof McpAccessToken) {
            return $token;
        }

        throw new McpAccessTokenNotFound();
    }

    /**
     * Every token of every user — the administrative listing behind
     * `app:mcp:token:list`, which runs on the box and is not scoped to anyone.
     *
     * Revoked and expired rows are INCLUDED on purpose: the list doubles as the
     * audit trail of what was ever handed out, and hiding a revoked token would
     * make "did I actually revoke it?" unanswerable. Status is a column.
     *
     * Ordering is newest-first, tie-broken by id so two tokens created inside
     * the same second (which `created_at` cannot tell apart) still come back in
     * a stable order rather than whatever the planner felt like.
     *
     * @return list<McpAccessToken>
     */
    public function listAll(): array
    {
        /** @var list<McpAccessToken> $tokens */
        $tokens = $this->entityManager->createQueryBuilder()
            ->from(McpAccessToken::class, 't')
            ->select('t')
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $tokens;
    }

    /**
     * One user's own tokens — the "Propojené aplikace" page, where a personal
     * access token sits next to the OAuth connections because from the user's
     * side both are "an app that can reach my projects".
     *
     * Same inclusive rule as {@see listAll()}: revoked and expired rows stay,
     * with their status as a column, so "did I actually revoke it?" has an
     * answer on screen.
     *
     * @return list<McpAccessToken>
     */
    public function listForUser(User $user): array
    {
        /** @var list<McpAccessToken> $tokens */
        $tokens = $this->entityManager->createQueryBuilder()
            ->from(McpAccessToken::class, 't')
            ->select('t')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $tokens;
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
