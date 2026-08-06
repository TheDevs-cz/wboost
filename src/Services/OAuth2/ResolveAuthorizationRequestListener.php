<?php

declare(strict_types=1);

namespace WBoost\Web\Services\OAuth2;

use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Entity\User;
use WBoost\Web\Repository\OAuthClientApprovalRepository;
use WBoost\Web\Value\ConsentDecision;
use WBoost\Web\Value\PendingAuthorization;

/**
 * Decides every authorization request at `/api/authorize` — the seam between
 * league's stateless authorization controller and the user actually agreeing to
 * something (S8-T5).
 *
 * The bundle dispatches this event with the resource owner ALREADY resolved
 * (its factory reads `Security::getUser()` off the `main` firewall's session,
 * which is why `config/packages/security.php` demands `IS_AUTHENTICATED_FULLY`
 * on this path rather than leaving it public). What the event still needs is a
 * verdict, and the default is
 * {@see AuthorizationRequestResolveEvent::AUTHORIZATION_DENIED}: without a
 * listener the endpoint would refuse everything it was ever given.
 *
 * ## What replaced the auto-approval, and why it had to
 *
 * Until this task the listener approved every request on sight: a logged-in
 * user visiting `/api/authorize` handed the client a code without being asked
 * and without ever seeing which scopes were requested. Combined with dynamic
 * client registration (S8-T4) that is a one-click account takeover — anyone
 * registers a client pointing at a host they own, sends a logged-in user a
 * link, and receives a token scoped to that user's projects. PKCE cannot close
 * it (the attacker IS the client and holds the verifier), and neither can
 * https-only redirect URIs or rate limiting. Being shown who is asking, for
 * what, and having to click, is what closes it.
 *
 * ## Three outcomes, in this order
 *
 * 1. **A decision the user just made** ({@see ConsentSession::takeDecision()}) —
 *    one-shot and fingerprinted to this exact (client, scopes). `Denied` is the
 *    only way a refusal reaches league at all, and resolving it as DENIED is
 *    what produces the RFC-correct `access_denied` at the client's redirect URI
 *    instead of a wboost error page.
 * 2. **A remembered approval** covering the request — no prompt. This is what
 *    keeps an hourly token renewal from putting a screen in front of the user
 *    (and a screen people see hourly is a screen people click through blind).
 *    The coverage test is one-directional: an approval may SUPPRESS a prompt,
 *    never widen a grant, so a client that comes back asking for MORE than was
 *    stored lands in case 3.
 * 3. **Ask** — park the request in the session and take the browser to the
 *    consent screen via `setResponse()`, which the bundle's controller returns
 *    instead of completing the authorization.
 *
 * `client_credentials` never reaches any of this: that grant has no resource
 * owner and no browser, and league dispatches this event only from the
 * authorization endpoint. The in-production REST API is untouched.
 */
#[AsEventListener(event: OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE)]
final readonly class ResolveAuthorizationRequestListener
{
    public function __construct(
        private ConsentSession $consentSession,
        private OAuthClientApprovalRepository $approvals,
        private DescribeConsentScopes $describeConsentScopes,
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AuthorizationRequestResolveEvent $event): void
    {
        $user = $event->getUser();

        // Defensive, and fail-closed: the bundle types the resource owner as a
        // bare UserInterface. Anything that is not one of our users has no
        // approval history and nobody for the voters to decide about, so it
        // gets the default verdict rather than a prompt.
        if ($user instanceof User === false) {
            $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_DENIED);

            return;
        }

        $client = $event->getClient();
        $clientIdentifier = $client->getIdentifier();
        $requested = self::requestedScopes($event);

        $decision = $this->consentSession->takeDecision($user->id->toString(), $clientIdentifier, $requested);

        if ($decision === ConsentDecision::Denied) {
            $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_DENIED);

            return;
        }

        if ($decision === ConsentDecision::Approved) {
            $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_APPROVED);

            return;
        }

        $effective = $this->describeConsentScopes->effectiveValues($requested);
        $approval = $this->approvals->findFor($user, $clientIdentifier);

        if ($approval !== null && $approval->covers($effective)) {
            $this->approvals->touchLastUsed($approval, $this->clock->now());
            $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_APPROVED);

            return;
        }

        $this->consentSession->begin(new PendingAuthorization(
            $clientIdentifier,
            self::clientName($client),
            $requested,
            $this->authorizeQuery(),
            $event->getRedirectUri(),
        ));

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('oauth_consent')));
    }

    /**
     * The query string to replay once the user has answered.
     *
     * Rebuilt from the parsed query rather than taken raw, so what is stored is
     * exactly what league will parse back (it reads the request's query params,
     * never the body — which is also why a POSTed authorization request could
     * not work here even before this listener existed).
     */
    private function authorizeQuery(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return '';
        }

        return http_build_query($request->query->all());
    }

    /**
     * @return list<string>
     */
    private static function requestedScopes(AuthorizationRequestResolveEvent $event): array
    {
        /** @var list<string> $scopes */
        $scopes = [];

        foreach ($event->getScopes() as $scope) {
            $value = (string) $scope;

            if (in_array($value, $scopes, true) === false) {
                $scopes[] = $value;
            }
        }

        return $scopes;
    }

    /**
     * The client's display name.
     *
     * `ClientInterface` only declares `getName()` in a `@method` annotation, so
     * the call is guarded: a client model without it degrades to the identifier
     * (which is never attacker-chosen) rather than fatally. The name itself is
     * NOT trusted — under dynamic registration it is a self-chosen string, and
     * the consent screen presents it as such next to the redirect host.
     */
    private static function clientName(object $client): string
    {
        if (method_exists($client, 'getName')) {
            $name = $client->getName();

            if (is_string($name) && trim($name) !== '') {
                return $name;
            }
        }

        if (method_exists($client, 'getIdentifier')) {
            $identifier = $client->getIdentifier();

            if (is_string($identifier)) {
                return $identifier;
            }
        }

        return '';
    }
}
