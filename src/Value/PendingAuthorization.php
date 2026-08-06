<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * The authorization request that is waiting for the user's answer, parked in
 * the session while the consent screen is shown.
 *
 * ## Why the whole query string is carried
 *
 * The bundle's authorization controller is stateless: everything it knows
 * (client, scopes, PKCE challenge, state, redirect URI) comes from the query
 * string of `/api/authorize`. Interrupting it with a consent screen therefore
 * means replaying that exact URL afterwards — one character different and the
 * PKCE challenge or the `state` the client is waiting for is gone.
 *
 * It is kept SERVER-SIDE, in the session, rather than round-tripped through a
 * form field: the consent POST then cannot be talked into redirecting anywhere
 * the user did not just arrive from, which is what stops this being an open
 * redirect on an authenticated POST.
 *
 * {@see fingerprint()} is what binds a decision to the request it answered —
 * see {@see ConsentDecision}.
 */
readonly final class PendingAuthorization
{
    /**
     * @param list<string> $scopes         raw scopes exactly as requested (NOT expanded)
     * @param string       $authorizeQuery the `/api/authorize` query string to replay verbatim
     * @param null|string  $redirectUri    where the client will be sent afterwards; shown to the
     *                                     user because it is the one thing an attacker cannot
     *                                     fake — unlike the self-chosen client name
     */
    public function __construct(
        public string $clientIdentifier,
        public string $clientName,
        public array $scopes,
        public string $authorizeQuery,
        public null|string $redirectUri,
    ) {
    }

    /**
     * Identifies the (user, client, scope set) this request is about,
     * order-insensitive in the scopes.
     *
     * Used to check that a decision coming back from the consent screen answers
     * THIS request: a decision minted for `templates:read` must not resolve a
     * request that meanwhile asks for `templates:design` too, and — belt and
     * braces, since a logout invalidates the session anyway — a decision made
     * by one account must not resolve another account's request.
     *
     * @param list<string> $scopes
     */
    public static function fingerprintOf(string $userIdentifier, string $clientIdentifier, array $scopes): string
    {
        $sorted = $scopes;
        sort($sorted);

        return hash('sha256', $userIdentifier . "\0" . $clientIdentifier . "\0" . implode(' ', $sorted));
    }

    /**
     * The host of {@see $redirectUri} — where the codes actually go.
     *
     * Null when there is no redirect URI or it has no host (which cannot happen
     * for a request league has already validated, but this is view data and a
     * malformed value must degrade to "not shown", never to a crash).
     */
    public function redirectHost(): null|string
    {
        if ($this->redirectUri === null) {
            return null;
        }

        $host = parse_url($this->redirectUri, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'clientIdentifier' => $this->clientIdentifier,
            'clientName' => $this->clientName,
            'scopes' => $this->scopes,
            'authorizeQuery' => $this->authorizeQuery,
            'redirectUri' => $this->redirectUri,
        ];
    }

    /**
     * Parses back what {@see toArray()} wrote, DEFENSIVELY: a session written by
     * an older release (or a hand-edited one) must degrade to "no pending
     * request" — which shows the "the request expired" notice — rather than
     * fatally on a missing key.
     */
    public static function fromArray(mixed $data): null|self
    {
        if (is_array($data) === false) {
            return null;
        }

        $clientIdentifier = $data['clientIdentifier'] ?? null;
        $clientName = $data['clientName'] ?? null;
        $scopes = $data['scopes'] ?? null;
        $authorizeQuery = $data['authorizeQuery'] ?? null;
        $redirectUri = $data['redirectUri'] ?? null;

        if (!is_string($clientIdentifier) || !is_string($clientName) || !is_string($authorizeQuery)) {
            return null;
        }

        if (!is_array($scopes)) {
            return null;
        }

        /** @var list<string> $scopeValues */
        $scopeValues = [];

        foreach ($scopes as $scope) {
            if (!is_string($scope)) {
                return null;
            }

            $scopeValues[] = $scope;
        }

        return new self(
            $clientIdentifier,
            $clientName,
            $scopeValues,
            $authorizeQuery,
            is_string($redirectUri) ? $redirectUri : null,
        );
    }
}
