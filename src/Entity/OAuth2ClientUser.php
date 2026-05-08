<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use JetBrains\PhpStorm\Immutable;

/**
 * Mapping between an OAuth2 client (issued by league/oauth2-server-bundle) and the
 * App User the client acts on behalf of. Populated when a client is created via
 * `app:oauth-client:create`. Read by IssueAccessTokenWithUserListener to inject
 * the App User UUID into the JWT `sub` claim.
 */
#[Entity]
#[Table(name: 'oauth2_client_user')]
class OAuth2ClientUser
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(length: 32)]
        readonly public string $clientIdentifier,

        #[Immutable]
        #[ManyToOne(targetEntity: User::class)]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        readonly public User $user,
    ) {
    }
}
