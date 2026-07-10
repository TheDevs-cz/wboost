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
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\SocialProvider;

/**
 * A third-party identity (today: Facebook) linked to an app user — used both
 * for social sign-in and as the credential for publishing to the user's
 * Facebook Pages / Instagram professional accounts.
 *
 * `accessToken` holds the ~60-day LONG-LIVED user token, encrypted via
 * TokenCrypto — never plaintext. Publish destinations (Pages, IG accounts) are
 * deliberately NOT persisted: they're fetched live from the Graph API at
 * publish time, so revoked/renamed assets can never go stale here.
 */
#[Entity]
#[Table(name: 'social_account')]
#[UniqueConstraint(name: 'uniq_social_account_provider_user', columns: ['provider', 'provider_user_id'])]
#[UniqueConstraint(name: 'uniq_social_account_user_provider', columns: ['user_id', 'provider'])]
class SocialAccount
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT)]
    public string $accessToken;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $tokenExpiresAt;

    /** @var list<string> */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON)]
    public array $scopes;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $displayName;

    /**
     * Set when a Graph call fails with an invalid/expired token (code 190) or
     * the stored ciphertext can't be decrypted — surfaces a "reconnect" CTA.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => false])]
    public bool $needsReconnect = false;

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
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public User $user,

        #[Immutable]
        #[Column(type: 'string', enumType: SocialProvider::class)]
        readonly public SocialProvider $provider,

        #[Immutable]
        #[Column]
        readonly public string $providerUserId,

        string $encryptedAccessToken,
        null|DateTimeImmutable $tokenExpiresAt,
        array $scopes,
        null|string $displayName,
        DateTimeImmutable $connectedAt,
    ) {
        $this->accessToken = $encryptedAccessToken;
        $this->tokenExpiresAt = $tokenExpiresAt;
        $this->scopes = $scopes;
        $this->displayName = $displayName;
        $this->connectedAt = $connectedAt;
    }

    /**
     * Reconnect / scope upgrade: replaces the stored token and clears the
     * reconnect flag.
     *
     * @param list<string> $scopes
     */
    public function updateToken(
        string $encryptedAccessToken,
        null|DateTimeImmutable $tokenExpiresAt,
        array $scopes,
        null|string $displayName,
        DateTimeImmutable $now,
    ): void {
        $this->accessToken = $encryptedAccessToken;
        $this->tokenExpiresAt = $tokenExpiresAt;
        $this->scopes = $scopes;
        $this->displayName = $displayName ?? $this->displayName;
        $this->needsReconnect = false;
        $this->connectedAt = $now;
    }

    public function markNeedsReconnect(): void
    {
        $this->needsReconnect = true;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
