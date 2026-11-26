<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\SocialPlatform;

/**
 * The social account a PROJECT publishes to — one per project and platform.
 * Today: an Instagram professional account connected through "Instagram API
 * with Instagram Login" (no Facebook Page involved). The same account may be
 * connected to several projects.
 *
 * - `accountId`: the id publishing addresses (Instagram: `/me` `user_id`;
 *   a Facebook Page: the page id).
 * - `accountUserId`: the id Meta's deauthorize / data-deletion callbacks may
 *   carry (Instagram: the app-scoped `/me` `id`; Facebook: the person's id).
 *
 * Whoever can fill the project's templates may publish; connecting and
 * disconnecting needs `project_edit` (docs/architecture/social-publishing.md).
 *
 * `accessToken` is encrypted via TokenCrypto — never plaintext. Instagram
 * tokens live ~60 days and must be refreshed before they expire
 * (`app:instagram:refresh-tokens`, daily cron); an unrefreshed token dies for
 * good and the project has to reconnect.
 */
#[Entity]
#[Table(name: 'project_social_account')]
#[UniqueConstraint(name: 'uniq_project_social_account_platform', columns: ['project_id', 'platform'])]
#[Index(name: 'idx_project_social_account_account', columns: ['platform', 'account_id'])]
class ProjectSocialAccount
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column]
    public string $accountId;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $accountUserId;

    /** Instagram handle (without @); null for a Facebook Page. */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $username;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $name;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $accountType;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT)]
    public string $accessToken;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $tokenExpiresAt;

    /** When the current token was issued (connect or refresh) — Instagram refreshes only tokens ≥ 24 h old. */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $tokenIssuedAt;

    /** @var list<string> */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON)]
    public array $scopes;

    /**
     * Set when the network rejects the token (code 190), a refresh fails for
     * good or the stored ciphertext can't be decrypted — shows "Propojit
     * znovu" and blocks publishing until someone reconnects.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => false])]
    public bool $needsReconnect = false;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public null|User $connectedBy;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $connectedAt;

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        readonly public Project $project,

        #[Immutable]
        #[Column(type: 'string', enumType: SocialPlatform::class)]
        readonly public SocialPlatform $platform,

        string $accountId,
        null|string $accountUserId,
        null|string $username,
        null|string $name,
        null|string $accountType,
        string $encryptedAccessToken,
        null|DateTimeImmutable $tokenExpiresAt,
        array $scopes,
        null|User $connectedBy,
        DateTimeImmutable $now,
    ) {
        $this->accountId = $accountId;
        $this->accountUserId = $accountUserId;
        $this->username = $username;
        $this->name = $name;
        $this->accountType = $accountType;
        $this->accessToken = $encryptedAccessToken;
        $this->tokenExpiresAt = $tokenExpiresAt;
        $this->scopes = $scopes;
        $this->connectedBy = $connectedBy;
        $this->connectedAt = $now;
        $this->tokenIssuedAt = $now;
    }

    /**
     * Reconnect (possibly to a different account): replaces the identity and
     * token, clears the reconnect flag.
     *
     * @param list<string> $scopes
     */
    public function reconnect(
        string $accountId,
        null|string $accountUserId,
        null|string $username,
        null|string $name,
        null|string $accountType,
        string $encryptedAccessToken,
        null|DateTimeImmutable $tokenExpiresAt,
        array $scopes,
        null|User $connectedBy,
        DateTimeImmutable $now,
    ): void {
        $this->accountId = $accountId;
        $this->accountUserId = $accountUserId;
        $this->username = $username;
        $this->name = $name;
        $this->accountType = $accountType;
        $this->accessToken = $encryptedAccessToken;
        $this->tokenExpiresAt = $tokenExpiresAt;
        $this->scopes = $scopes;
        $this->connectedBy = $connectedBy;
        $this->connectedAt = $now;
        $this->tokenIssuedAt = $now;
        $this->needsReconnect = false;
    }

    public function refreshToken(string $encryptedAccessToken, null|DateTimeImmutable $tokenExpiresAt, DateTimeImmutable $now): void
    {
        $this->accessToken = $encryptedAccessToken;
        $this->tokenExpiresAt = $tokenExpiresAt;
        $this->tokenIssuedAt = $now;
    }

    public function markNeedsReconnect(): void
    {
        $this->needsReconnect = true;
    }

    public function isUsable(DateTimeImmutable $now): bool
    {
        return !$this->needsReconnect
            && ($this->tokenExpiresAt === null || $this->tokenExpiresAt > $now);
    }

    /** "@handle" for Instagram, the page name otherwise. */
    public function displayName(): string
    {
        return $this->username !== null ? '@' . $this->username : ($this->name ?? $this->accountId);
    }

    /**
     * Instagram refreshes only tokens at least 24 hours old; refresh once the
     * token is within `$window` of its expiry. Tokens without an expiry
     * (Facebook Page tokens) are never due.
     */
    public function isDueForRefresh(DateTimeImmutable $now, string $window = '+30 days'): bool
    {
        if ($this->needsReconnect || $this->tokenExpiresAt === null || $this->tokenExpiresAt <= $now) {
            return false;
        }

        return $this->tokenIssuedAt <= $now->modify('-24 hours')
            && $this->tokenExpiresAt <= $now->modify($window);
    }
}
