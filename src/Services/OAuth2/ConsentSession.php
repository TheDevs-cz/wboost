<?php

declare(strict_types=1);

namespace WBoost\Web\Services\OAuth2;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use WBoost\Web\Value\ConsentDecision;
use WBoost\Web\Value\PendingAuthorization;

/**
 * The only piece of state the consent interstitial needs, and the only place
 * that knows the session keys.
 *
 * The flow it serves is a three-legged bounce inside one browser tab:
 *
 * 1. `/api/authorize` — {@see ResolveAuthorizationRequestListener} finds no
 *    remembered approval, calls {@see begin()} and redirects to the screen;
 * 2. `/oauth/consent` — the controller renders {@see pending()}, and on POST
 *    calls {@see recordDecision()} before sending the browser back;
 * 3. `/api/authorize` again — the listener {@see takeDecision()}s and resolves.
 *
 * Everything lives server-side. The consent form carries no client id, no scope
 * list and no return URL, so nothing a user can edit in the POST body can
 * change WHICH authorization is being answered — only whether the answer is yes
 * or no.
 *
 * Both stored values are single-use and the decision is additionally bound to
 * the (client, scopes) fingerprint it was given for, so a stale "approved" left
 * behind by an abandoned tab cannot silently answer a later request.
 */
readonly final class ConsentSession
{
    private const string PENDING_KEY = 'oauth2_consent_pending';

    private const string DECISION_KEY = 'oauth2_consent_decision';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function begin(PendingAuthorization $pending): void
    {
        $this->session()?->set(self::PENDING_KEY, $pending->toArray());
    }

    public function pending(): null|PendingAuthorization
    {
        return PendingAuthorization::fromArray($this->session()?->get(self::PENDING_KEY));
    }

    /**
     * Answers the parked request and clears it in one move — the pending entry
     * has done its job, and leaving it behind would let a reload of the consent
     * page answer the same authorization twice.
     */
    public function recordDecision(
        string $userIdentifier,
        PendingAuthorization $pending,
        ConsentDecision $decision,
    ): void {
        $session = $this->session();

        if ($session === null) {
            return;
        }

        $session->remove(self::PENDING_KEY);
        $session->set(self::DECISION_KEY, [
            'fingerprint' => PendingAuthorization::fingerprintOf(
                $userIdentifier,
                $pending->clientIdentifier,
                $pending->scopes,
            ),
            'decision' => $decision->value,
        ]);
    }

    /**
     * Consumes the decision, if there is one for exactly this (client, scopes).
     *
     * Consuming is what makes it one-shot: a connector that re-runs the
     * authorization an hour later gets no leftover verdict, it gets the
     * PERSISTED approval path (or a fresh prompt). A fingerprint mismatch also
     * clears the value rather than leaving it lying around — it belonged to a
     * request that is no longer being made.
     *
     * @param list<string> $scopes raw requested scopes, as they arrived
     */
    public function takeDecision(
        string $userIdentifier,
        string $clientIdentifier,
        array $scopes,
    ): null|ConsentDecision {
        $session = $this->session();

        if ($session === null) {
            return null;
        }

        $stored = $session->get(self::DECISION_KEY);
        $session->remove(self::DECISION_KEY);

        if (!is_array($stored)) {
            return null;
        }

        $fingerprint = $stored['fingerprint'] ?? null;
        $decision = $stored['decision'] ?? null;

        if (!is_string($fingerprint) || !is_string($decision)) {
            return null;
        }

        $expected = PendingAuthorization::fingerprintOf($userIdentifier, $clientIdentifier, $scopes);

        if (hash_equals($expected, $fingerprint) === false) {
            return null;
        }

        return ConsentDecision::tryFrom($decision);
    }

    public function clear(): void
    {
        $session = $this->session();

        $session?->remove(self::PENDING_KEY);
        $session?->remove(self::DECISION_KEY);
    }

    /**
     * The session of the request being handled, or null when there is none.
     *
     * `RequestStack::getSession()` THROWS in that case, and both callers of this
     * class run on paths where a session is guaranteed (`/api/authorize` and
     * `/oauth/consent` are both behind `IS_AUTHENTICATED_FULLY` on the
     * session-backed `main` firewall) — but a service that turns a missing
     * session into a 500 would make every future reuse of it a landmine, so it
     * degrades to "no pending request" instead.
     */
    private function session(): null|SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null || $request->hasSession() === false) {
            return null;
        }

        return $request->getSession();
    }
}
