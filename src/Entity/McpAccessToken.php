<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\McpAccessTokenStatus;

/**
 * A personal access token that authenticates ONE user against the MCP server
 * at `/_mcp`. Authorisation still runs through the normal voters — the token
 * only resolves the acting user and narrows what they may do via `scopes`.
 *
 * **The secret never reaches the database.** Only the sha256 of the whole wire
 * token (`wb_mcp_<32 bytes base64url>`) is stored, so a dump carries nothing
 * replayable and a lost token is unrecoverable: it is shown once at creation
 * and can only be replaced. Generation and hashing live in exactly one place,
 * {@see \WBoost\Web\Mcp\Security\McpTokenGenerator} — this entity deliberately
 * exposes no way to hold or read a plaintext secret.
 *
 * `scopes` holds the raw scope strings (`templates:read`, …); mapping them to
 * the `McpScope` enum is the security layer's job, and an unknown string must
 * degrade to "not granted" rather than break a token that a later release
 * stopped understanding.
 */
#[Entity]
#[Table(name: 'mcp_access_token')]
class McpAccessToken
{
    /**
     * Touched on authentication, throttled to at most once a minute per token
     * so a chatty agent does not turn every tool call into a write.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $lastUsedAt = null;

    /** Set once, never cleared — a revoked token is dead, not disabled. */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $revokedAt = null;

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        readonly public User $user,

        /** Human label shown in the token list ("Claude Code — laptop"). */
        #[Column]
        readonly public string $name,

        /** @var list<string> */
        #[Immutable]
        #[Column(type: Types::JSON)]
        readonly public array $scopes,

        /** sha256 hex of the whole wire token — 64 chars, never the secret. */
        #[Immutable]
        #[Column(length: 64, unique: true)]
        readonly public string $tokenHash,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $createdAt,

        /** Null = never expires. */
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        readonly public null|DateTimeImmutable $expiresAt = null,
    ) {
    }

    public function markUsed(DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
    }

    public function revoke(DateTimeImmutable $now): void
    {
        if ($this->revokedAt !== null) {
            return;
        }

        $this->revokedAt = $now;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    /**
     * The token's lifecycle state at `$now` — the one place that decides it.
     *
     * **Revocation wins over expiry** when both apply: it is an operator's
     * explicit act, and reporting "expired" would read as if the token had
     * merely aged out, hiding the fact that someone killed it. (Nothing depends
     * on the precedence for ACCESS — either state denies — so it is purely a
     * question of what the listing tells a human.)
     *
     * Expiry is exclusive at the exact instant, matching the `expiresAt > :now`
     * in `findActiveByHash()`: this must answer the same question the
     * authentication query does, or the listing would call a token active that
     * cannot log in.
     */
    public function status(DateTimeImmutable $now): McpAccessTokenStatus
    {
        if ($this->revokedAt !== null) {
            return McpAccessTokenStatus::Revoked;
        }

        if ($this->expiresAt !== null && $this->expiresAt <= $now) {
            return McpAccessTokenStatus::Expired;
        }

        return McpAccessTokenStatus::Active;
    }

    /**
     * The instant that EXPLAINS {@see status()} — when it was revoked, when it
     * expired, null while it is active. Saves every caller from re-deciding
     * which of the two nullable columns is the interesting one.
     */
    public function statusChangedAt(DateTimeImmutable $now): null|DateTimeImmutable
    {
        return match ($this->status($now)) {
            McpAccessTokenStatus::Active => null,
            McpAccessTokenStatus::Revoked => $this->revokedAt,
            McpAccessTokenStatus::Expired => $this->expiresAt,
        };
    }

    /**
     * Mirrors the `findActiveByHash()` predicate for callers that already hold
     * the entity. A thin reading of {@see status()} rather than a second copy
     * of the rules — two predicates that can disagree is exactly the bug this
     * avoids.
     */
    public function isActive(DateTimeImmutable $now): bool
    {
        return $this->status($now) === McpAccessTokenStatus::Active;
    }
}
