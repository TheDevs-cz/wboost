<?php

declare(strict_types=1);

namespace WBoost\Web\Services\OAuth2;

use League\Bundle\OAuth2ServerBundle\Event\ScopeResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Ramsey\Uuid\Uuid;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Mcp\Security\McpScopeCeiling;
use WBoost\Web\Repository\UserRepository;

/**
 * Caps the scopes of every USER-BOUND grant to the admin-set ceiling
 * ({@see \WBoost\Web\Entity\User::$mcpScopes}) — the enforcement half; the
 * consent screen only shows the capped list
 * ({@see ResolveAuthorizationRequestListener}).
 *
 * The consent listener cannot do this on its own: league's authorization event
 * offers no way to change the requested scopes, so the authorization code
 * would still carry everything the client asked for. `finalizeScopes()` is
 * where league settles what a code or token actually holds, and the bundle
 * dispatches this event from it.
 *
 * A grant with no user (the REST API's `client_credentials`, whose `sub` is
 * stamped later by IssueAccessTokenWithUserListener) is left alone, as is a
 * user identifier that names nobody — the authenticator refuses such a token
 * anyway.
 */
#[AsEventListener(event: OAuth2Events::SCOPE_RESOLVE)]
final readonly class CapScopesToUserCeilingListener
{
    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    public function __invoke(ScopeResolveEvent $event): void
    {
        $identifier = $event->getUserIdentifier();

        if (is_string($identifier) === false || Uuid::isValid($identifier) === false) {
            return;
        }

        try {
            $user = $this->userRepository->getById(Uuid::fromString($identifier));
        } catch (UserNotFound) {
            return;
        }

        $capped = McpScopeCeiling::cap(
            $user,
            array_map(static fn (Scope $scope): string => (string) $scope, $event->getScopes()),
        );

        // Rebuilt rather than filtered: capping can ADD a scope (design above
        // a read-only ceiling becomes the read it implies), which filtering
        // the original objects would silently lose.
        $scopes = [];

        foreach ($capped as $value) {
            if ($value !== '') {
                $scopes[] = new Scope($value);
            }
        }

        $event->setScopes(...$scopes);
    }
}
