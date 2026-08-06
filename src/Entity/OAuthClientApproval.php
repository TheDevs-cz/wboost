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

/**
 * One user's standing decision about one OAuth2 client: "I have seen this
 * application, and I agreed it may do THESE things on my behalf."
 *
 * It exists so that a connector does not re-prompt every hour — the access
 * token TTL is `PT1H` and refresh tokens rotate, so without a remembered
 * decision every renewal would put a screen in front of the user, and a screen
 * people see hourly is a screen people click through blind.
 *
 * ## `scopes` is the SECURITY-relevant column
 *
 * It holds the EFFECTIVE set the user was shown — the requested scopes plus
 * everything they imply, as expanded by
 * {@see \WBoost\Web\Services\OAuth2\DescribeConsentScopes::effectiveValues()}.
 * {@see covers()} is what turns it into a decision, and the rule is deliberately
 * one-directional: a stored approval may SUPPRESS a prompt, never WIDEN a
 * grant. A client that comes back asking for more than is stored here is sent
 * back to the consent screen, which is the whole reason the scopes are stored
 * at all rather than a bare "user X trusts client Y" flag.
 *
 * There is at most ONE row per (user, client) — see the unique constraint —
 * and {@see approve()} merges rather than replaces, so the row accumulates
 * everything the user has ever agreed to for that client. Every merged scope
 * was displayed on a consent screen at the moment it was added; nothing can
 * enter this column without having been shown.
 *
 * ## Revocation is a DELETE, not a flag
 *
 * Unlike {@see McpAccessToken}, whose row survives revocation as an audit
 * trail, disconnecting an app removes this row
 * ({@see \WBoost\Web\MessageHandler\OAuth2\RevokeOAuthClientApprovalHandler}).
 * A revoked-but-present approval is a permanent hazard: every future read of it
 * has to remember to filter, and the one that forgets silently re-grants access
 * the user believed they had taken away. The credentials that outlive the row
 * are killed in the same transaction, so nothing usable is left behind.
 */
#[Entity]
#[Table(name: 'oauth_client_approval')]
#[UniqueConstraint(name: 'uniq_oauth_client_approval_user_client', columns: ['user_id', 'client_identifier'])]
class OAuthClientApproval
{
    /**
     * When a REMEMBERED approval last let an authorization through without
     * asking — i.e. when the app last reconnected. Written by
     * {@see \WBoost\Web\Repository\OAuthClientApprovalRepository::touchLastUsed()},
     * shown on the "connected apps" page so a user can tell a live integration
     * from one they forgot about.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $lastUsedAt = null;

    /**
     * @param list<string> $scopes the EFFECTIVE (implication-expanded) set shown to the user
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

        /**
         * `oauth2_client.identifier` — a plain column, NOT a foreign key, which
         * mirrors {@see OAuth2ClientUser} (the app's other reference into the
         * bundle's tables). Deleting a client cascades its access tokens away
         * anyway, so an orphaned approval grants nothing; the listing skips it.
         */
        #[Immutable]
        #[Column(length: 32)]
        readonly public string $clientIdentifier,

        /** @var list<string> */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSON)]
        public array $scopes,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $approvedAt,
    ) {
    }

    /**
     * Records a fresh approval on top of an existing one.
     *
     * The scope sets are UNIONed, not replaced: each side was displayed on a
     * consent screen when it was agreed to, and OAuth has no notion of an
     * approval shrinking — a token only ever carries what its authorization
     * request asked for, so keeping the older scope cannot widen any token that
     * does not ask for it. Re-approving also re-stamps `approvedAt`, because
     * "when did I last agree to this?" is the question the management page
     * answers.
     *
     * @param list<string> $scopes
     */
    public function approve(array $scopes, DateTimeImmutable $now): void
    {
        /** @var list<string> $merged */
        $merged = $this->scopes;

        foreach ($scopes as $scope) {
            if (in_array($scope, $merged, true) === false) {
                $merged[] = $scope;
            }
        }

        $this->scopes = $merged;
        $this->approvedAt = $now;
    }

    /**
     * Whether this approval already covers everything `$requested` asks for —
     * the one question that decides prompt vs. no prompt.
     *
     * Fails closed on the empty request too: an authorization asking for no
     * scopes at all is not "trivially covered", it is a request the user has
     * never seen, and the bundle's default-scope listener may still stamp
     * something onto it. Requiring a non-empty request keeps the "the user saw
     * this" invariant total.
     *
     * @param list<string> $requested effective (expanded) scopes of the incoming request
     */
    public function covers(array $requested): bool
    {
        if ($requested === []) {
            return false;
        }

        foreach ($requested as $scope) {
            if (in_array($scope, $this->scopes, true) === false) {
                return false;
            }
        }

        return true;
    }

    public function markUsed(DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
    }
}
