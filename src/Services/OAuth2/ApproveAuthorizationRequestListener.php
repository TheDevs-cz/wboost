<?php

declare(strict_types=1);

namespace WBoost\Web\Services\OAuth2;

use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Resolves an authorization request for the user who is already logged in.
 *
 * The bundle's `AuthorizationController` dispatches this event with the
 * resource owner ALREADY resolved — its factory reads `Security::getUser()` off
 * the `main` firewall's session (and throws if there is none, which is why
 * `config/packages/security.php` demands `IS_AUTHENTICATED_FULLY` on
 * `/api/authorize` rather than leaving it public). What the event still needs is
 * a verdict, because the default is
 * {@see AuthorizationRequestResolveEvent::AUTHORIZATION_DENIED}: without a
 * listener the endpoint would reject every request it was ever given.
 *
 * ## ⚠️ Known gap: this AUTO-APPROVES — there is no consent screen yet (S8-T5)
 *
 * A logged-in user visiting `/api/authorize` hands the client a code without
 * being asked, and without ever seeing which scopes were requested. That is a
 * real security consideration and it is accepted only because of what is NOT
 * built yet:
 *
 * - **Clients are operator-created.** There is no dynamic client registration
 *   (S8-T4), so every `client_id` and every `redirect_uri` was entered by hand
 *   via `app:oauth-client:create`. A third party cannot get itself registered
 *   and then silently collect tokens.
 * - **Scopes cannot exceed the user.** {@see \WBoost\Web\Mcp\Security\McpScope}
 *   narrows what a token may reach; the ordinary voters still decide what the
 *   underlying user may touch.
 *
 * S8-T5 replaces this listener with one that renders the Czech consent screen
 * and calls `setResponse()` (a redirect to the consent page) on first contact,
 * only resolving the authorization once the user has approved. The seam is
 * exactly this method — nothing else in the flow needs to change.
 */
#[AsEventListener(event: OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE)]
final readonly class ApproveAuthorizationRequestListener
{
    public function __invoke(AuthorizationRequestResolveEvent $event): void
    {
        $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_APPROVED);
    }
}
