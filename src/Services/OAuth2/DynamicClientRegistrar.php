<?php

declare(strict_types=1);

namespace WBoost\Web\Services\OAuth2;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use WBoost\Web\Exceptions\ClientRegistrationFailed;
use WBoost\Web\Mcp\Security\McpScope;

/**
 * RFC 7591 Dynamic Client Registration (S8-T4) — **the whole ceiling lives
 * here**. Everything an anonymous stranger may say about the client they are
 * registering is either validated in this class or dropped by it; the
 * controller only moves JSON in and out.
 *
 * It exists because claude.ai and ChatGPT connectors register themselves on
 * first connect. `league/oauth2-server-bundle` has no registration endpoint of
 * its own, but everything needed is public API:
 * {@see ClientManagerInterface::save()} plus the {@see Client} model.
 *
 * ## ⚠️ Why this was unreachable until consent existed — read this first
 *
 * The endpoint in front of this class is OFF by default
 * (`OAUTH2_DYNAMIC_CLIENT_REGISTRATION`), and the reason was never caution
 * about the code below. It was that open registration composed with an
 * AUTO-APPROVING authorization endpoint into a one-click account takeover:
 *
 * 1. anyone registers a client whose `redirect_uri` is a host they own;
 * 2. they send a logged-in wboost user a link to `/api/authorize`;
 * 3. the listener approves it without the user seeing anything;
 * 4. the code lands on the attacker's host and they exchange it for a token
 *    scoped to that user's projects.
 *
 * **PKCE does not help.** PKCE stops a THIRD party stealing a code in flight;
 * here the attacker IS the client and holds the verifier. Neither do https-only
 * redirect URIs (an attacker can own an https host), nor rate limiting. The
 * only thing that closes it is the user being shown who is asking and for what
 * — and step 3 no longer exists: since S8-T5,
 * {@see ResolveAuthorizationRequestListener} parks a first-time authorization
 * on a Czech consent screen that names the application, the account and every
 * scope, and only a click approves it. That is what makes the flag safe to
 * turn on; the constraints below bound what a registration can BE, never
 * whether the user agreed to it.
 *
 * ## No outbound fetch, ever
 *
 * This class has no HTTP client and must not acquire one. RFC 7591 metadata
 * carries several URL-valued fields (`logo_uri`, `client_uri`, `policy_uri`,
 * `tos_uri`, `jwks_uri`) supplied by an unauthenticated caller; dereferencing
 * any of them from inside the network that also hosts Gotenberg, Minio, Redis
 * and Postgres is a textbook SSRF. They are therefore IGNORED — not stored, not
 * echoed, not resolved — which RFC 7591 §2 explicitly permits ("the
 * authorization server MAY … ignore" unsupported metadata). The registration
 * response echoes only what was actually registered, so a caller cannot come
 * away believing we accepted a field we discarded.
 *
 * That is also why **Client ID Metadata Documents are not implemented** even
 * though the MCP specification lists them as SHOULD: a CIMD `client_id` IS an
 * https URL that the server dereferences, and
 * `oauth2_client.identifier` is `VARCHAR(32)`, hard-coded in the bundle's
 * mapping driver (`League\Bundle\OAuth2ServerBundle\Persistence\Mapping\Driver::buildClientMetadata`)
 * and referenced by three foreign keys plus our own `oauth2_client_user`.
 * Supporting it means overriding a private mapping driver and rewriting those
 * columns — for a draft spec no shipping connector uses today.
 */
final readonly class DynamicClientRegistrar
{
    /**
     * `oauth2_client.identifier` is `VARCHAR(32)` and is the table's PRIMARY
     * KEY, so this is a hard ceiling, not a preference.
     */
    private const int IDENTIFIER_LENGTH = 32;

    /**
     * Marks a row as machine-registered. It costs 4 of the 32 characters (the
     * remaining 28 hex chars still carry 112 bits, and a `client_id` is public
     * by definition) and buys the one thing the bundle's schema cannot give us:
     * a way to tell dynamic clients from operator-created ones in
     * `app:oauth-client:list`, and a selector for the "expire clients that
     * never completed a flow" job that has to exist before the flag is flipped.
     */
    private const string IDENTIFIER_PREFIX = 'dcr_';

    /** `oauth2_client.name` is `VARCHAR(128)`. */
    private const int CLIENT_NAME_MAX_LENGTH = 128;

    private const string DEFAULT_CLIENT_NAME = 'Unnamed MCP client';

    /**
     * Enough for a native client that registers one loopback port per platform,
     * far too few to use registration as a way to store data in our database.
     */
    private const int MAX_REDIRECT_URIS = 5;

    /**
     * A registered client may ASK for these; it is given
     * {@see self::GRANTED_GRANTS} regardless. Anything else — `implicit`,
     * `password`, and above all `client_credentials` — is refused: those are
     * the grants that issue a token with NO user in the loop, and
     * client_credentials is what the in-production REST API uses.
     */
    private const array REQUESTABLE_GRANTS = [
        OAuth2Grants::AUTHORIZATION_CODE,
        OAuth2Grants::REFRESH_TOKEN,
    ];

    /**
     * What every dynamic client is actually registered with, whatever it asked
     * for.
     *
     * `refresh_token` is not optional and leaving it out is a trap that only
     * fires later: league ISSUES a refresh token with every authorization-code
     * exchange whether or not the client named the grant, but
     * `ClientRepository::validateClient()` refuses to REDEEM one unless the
     * client names it. A client registered with `authorization_code` alone
     * therefore receives, once an hour, a credential its own registration
     * forbids it to use — and the failure surfaces as `unsupported_grant_type`
     * an hour after everything looked fine.
     * {@see \WBoost\Web\Tests\TestingOAuthClient} registers exactly this pair
     * for the same reason.
     */
    private const array GRANTED_GRANTS = [
        OAuth2Grants::AUTHORIZATION_CODE,
        OAuth2Grants::REFRESH_TOKEN,
    ];

    /** The only response type an OAuth 2.1 authorization-code client needs. */
    private const string RESPONSE_TYPE_CODE = 'code';

    /**
     * Public clients only. A confidential client would mean minting a secret
     * for an anonymous caller and storing it, and it would make PKCE optional
     * (`require_code_challenge_for_public_clients`) — i.e. strictly less
     * security in exchange for a credential we then have to protect. RFC 7591
     * §3.2.1 lets the server substitute metadata, and the response says `none`,
     * so a caller that omitted the field (whose RFC default would be
     * `client_secret_basic`) is told what it actually got.
     */
    private const string TOKEN_ENDPOINT_AUTH_METHOD_NONE = 'none';

    /** Collisions are astronomically unlikely; a bounded retry beats a 500. */
    private const int IDENTIFIER_ATTEMPTS = 5;

    public function __construct(
        private ClientManagerInterface $clientManager,
    ) {
    }

    /**
     * The scope ceiling: an MCP connector may be granted MCP scopes and nothing
     * else.
     *
     * Derived from {@see McpScope} rather than listed, so a new case is offered
     * the day it is declared. The omission that matters is the legacy blanket
     * `api` scope of the client_credentials REST API — it is registered in
     * `league_oauth2_server.scopes.available` (every existing token carries it)
     * and a self-registered client must never be able to ask for it.
     *
     * @return non-empty-list<non-empty-string>
     */
    public static function scopeCeiling(): array
    {
        return McpScope::values();
    }

    /**
     * @param array<array-key, mixed> $metadata the decoded JSON request body,
     *                                          entirely attacker-controlled
     *
     * @throws ClientRegistrationFailed
     */
    public function register(array $metadata): Client
    {
        $this->assertNoSoftwareStatement($metadata);
        $this->assertPublicClient($metadata);
        $this->assertResponseTypes($metadata);
        $this->assertRequestedGrants($metadata);

        $redirectUris = $this->redirectUris($metadata);
        $scopes = $this->scopes($metadata);

        $client = new Client($this->clientName($metadata), $this->mintIdentifier(), null);
        $client->setActive(true);
        $client->setRedirectUris(...$redirectUris);
        $client->setGrants(...array_map(
            static fn (string $grant): Grant => new Grant($grant),
            self::GRANTED_GRANTS,
        ));

        // LOAD-BEARING, and the failure it prevents happens at a distance: the
        // bundle's AddClientDefaultScopesListener stamps
        // `league_oauth2_server.scopes.default` (i.e. `api`) onto any client
        // saved with no scopes of its own, and ScopeRepository::setupScopes
        // then refuses everything outside that list. A client registered
        // without this line sails through `/api/authorize` and only fails when
        // the code is exchanged, with `invalid_scope`.
        $client->setScopes(...array_map(
            static fn (string $scope): Scope => new Scope($scope),
            $scopes,
        ));

        $this->clientManager->save($client);

        return $client;
    }

    /**
     * The registered `token_endpoint_auth_method` — a constant, because only
     * public clients are registered. Exposed so the controller's response and
     * the policy cannot drift.
     */
    public static function tokenEndpointAuthMethod(): string
    {
        return self::TOKEN_ENDPOINT_AUTH_METHOD_NONE;
    }

    /**
     * @return list<string>
     */
    public static function grantedResponseTypes(): array
    {
        return [self::RESPONSE_TYPE_CODE];
    }

    /**
     * We cannot verify a software statement — there is no trusted issuer list
     * and no key to check its signature against — so the honest answers are
     * "ignore it" (RFC 7591 §2.3 permits that) or "refuse it".
     *
     * Refusing is chosen deliberately. A client that sends a software statement
     * is asserting that its metadata is ATTESTED; silently substituting our own
     * values while returning 201 tells it the attestation was honoured when it
     * was not. No MCP connector sends one today, so nothing is lost, and the
     * client is told plainly rather than misled.
     *
     * @param array<array-key, mixed> $metadata
     *
     * @throws ClientRegistrationFailed
     */
    private function assertNoSoftwareStatement(array $metadata): void
    {
        if (array_key_exists('software_statement', $metadata) === false) {
            return;
        }

        throw ClientRegistrationFailed::invalidSoftwareStatement(
            'Software statements are not supported by this authorization server. '
            . 'Register without the "software_statement" field.',
        );
    }

    /**
     * @param array<array-key, mixed> $metadata
     *
     * @throws ClientRegistrationFailed
     */
    private function assertPublicClient(array $metadata): void
    {
        $method = $metadata['token_endpoint_auth_method'] ?? null;

        if ($method === null || $method === self::TOKEN_ENDPOINT_AUTH_METHOD_NONE) {
            return;
        }

        throw ClientRegistrationFailed::invalidClientMetadata(sprintf(
            'Only public clients can be registered dynamically, so '
            . '"token_endpoint_auth_method" must be "%s".',
            self::TOKEN_ENDPOINT_AUTH_METHOD_NONE,
        ));
    }

    /**
     * @param array<array-key, mixed> $metadata
     *
     * @throws ClientRegistrationFailed
     */
    private function assertResponseTypes(array $metadata): void
    {
        $responseTypes = $this->optionalStringList($metadata, 'response_types');

        if ($responseTypes === null) {
            return;
        }

        foreach ($responseTypes as $responseType) {
            if ($responseType !== self::RESPONSE_TYPE_CODE) {
                throw ClientRegistrationFailed::invalidClientMetadata(sprintf(
                    'Unsupported response type "%s". Only "%s" is offered.',
                    $responseType,
                    self::RESPONSE_TYPE_CODE,
                ));
            }
        }
    }

    /**
     * @param array<array-key, mixed> $metadata
     *
     * @throws ClientRegistrationFailed
     */
    private function assertRequestedGrants(array $metadata): void
    {
        $grantTypes = $this->optionalStringList($metadata, 'grant_types');

        if ($grantTypes === null) {
            return;
        }

        foreach ($grantTypes as $grantType) {
            if (in_array($grantType, self::REQUESTABLE_GRANTS, true) === false) {
                throw ClientRegistrationFailed::invalidClientMetadata(sprintf(
                    'Grant type "%s" cannot be registered dynamically. Available: %s.',
                    $grantType,
                    implode(', ', self::REQUESTABLE_GRANTS),
                ));
            }
        }
    }

    /**
     * The value objects, not the strings: {@see RedirectUri} demands a
     * `non-empty-string`, and the only thing that establishes it is
     * {@see RedirectUriPolicy::assertRegistrable()} — so the two happen in the
     * same loop and no unvalidated string can reach the model.
     *
     * @param array<array-key, mixed> $metadata
     *
     * @return list<RedirectUri>
     *
     * @throws ClientRegistrationFailed
     */
    private function redirectUris(array $metadata): array
    {
        $requested = $this->optionalStringList($metadata, 'redirect_uris');

        if ($requested === null || $requested === []) {
            throw ClientRegistrationFailed::invalidClientMetadata(
                'At least one redirect URI is required: "redirect_uris" must be a non-empty array of strings.',
            );
        }

        if (count($requested) > self::MAX_REDIRECT_URIS) {
            throw ClientRegistrationFailed::invalidRedirectUri(sprintf(
                'At most %d redirect URIs can be registered, %d given.',
                self::MAX_REDIRECT_URIS,
                count($requested),
            ));
        }

        /** @var list<RedirectUri> $redirectUris */
        $redirectUris = [];

        foreach ($requested as $redirectUri) {
            RedirectUriPolicy::assertRegistrable($redirectUri);

            $redirectUris[] = new RedirectUri($redirectUri);
        }

        return $redirectUris;
    }

    /**
     * @param array<array-key, mixed> $metadata
     *
     * @return non-empty-list<non-empty-string>
     *
     * @throws ClientRegistrationFailed
     */
    private function scopes(array $metadata): array
    {
        $requested = $metadata['scope'] ?? null;

        if ($requested === null) {
            // RFC 7591 §2 leaves the default to the server. All MCP scopes is
            // the useful one: `/api/authorize` narrows per authorization
            // anyway (the token carries what was asked for there, not what was
            // registered here), so a narrower default would only mean
            // connectors having to re-register to reach a tool.
            return McpScope::values();
        }

        if (is_string($requested) === false) {
            throw ClientRegistrationFailed::invalidClientMetadata(
                'The "scope" field must be a space-separated string of scope values.',
            );
        }

        $values = preg_split('/\s+/', trim($requested), -1, PREG_SPLIT_NO_EMPTY);

        /** @var list<non-empty-string> $scopes */
        $scopes = [];

        foreach ($values === false ? [] : $values as $value) {
            if (in_array($value, self::scopeCeiling(), true) === false) {
                throw ClientRegistrationFailed::invalidClientMetadata(sprintf(
                    'Scope "%s" cannot be registered dynamically. Available: %s.',
                    $value,
                    implode(' ', self::scopeCeiling()),
                ));
            }

            if (in_array($value, $scopes, true) === false) {
                $scopes[] = $value;
            }
        }

        // Reachable for `"scope": "   "`, and NOT a formality: an empty scope
        // list means `setScopes()` is called with no arguments, which is
        // precisely the state the bundle's default-scope listener overwrites
        // with the blanket `api` scope.
        if ($scopes === []) {
            throw ClientRegistrationFailed::invalidClientMetadata(
                'The "scope" field named no scopes. Omit it to receive the default set.',
            );
        }

        return $scopes;
    }

    /**
     * The name a human will read on the consent screen, so it is stripped of
     * control characters — a `\r` or a run of newlines is how you push the real
     * client name off a rendered line. It is NOT otherwise trusted: a
     * self-chosen display name is a phishing surface, and `oauth_consent.html.twig`
     * presents it as one — escaped, labelled "the application chooses this name
     * itself", and shown next to the redirect URI's host, which is the half of
     * the client's identity the authorization server actually validated.
     *
     * @param array<array-key, mixed> $metadata
     *
     * @throws ClientRegistrationFailed
     */
    private function clientName(array $metadata): string
    {
        $name = $metadata['client_name'] ?? null;

        if ($name === null) {
            return self::DEFAULT_CLIENT_NAME;
        }

        if (is_string($name) === false) {
            throw ClientRegistrationFailed::invalidClientMetadata('The "client_name" field must be a string.');
        }

        $name = preg_replace('/[\p{C}\s]+/u', ' ', $name);
        $name = trim($name ?? '');

        if ($name === '') {
            return self::DEFAULT_CLIENT_NAME;
        }

        return mb_substr($name, 0, self::CLIENT_NAME_MAX_LENGTH);
    }

    /**
     * @return non-empty-string
     */
    private function mintIdentifier(): string
    {
        $randomLength = (self::IDENTIFIER_LENGTH - strlen(self::IDENTIFIER_PREFIX)) / 2;

        for ($attempt = 0; $attempt < self::IDENTIFIER_ATTEMPTS; $attempt++) {
            $identifier = self::IDENTIFIER_PREFIX . bin2hex(random_bytes((int) $randomLength));

            if ($this->clientManager->find($identifier) === null) {
                return $identifier;
            }
        }

        throw new \RuntimeException('Could not mint a unique OAuth2 client identifier.');
    }

    /**
     * A JSON array of strings, or null when the field is absent. Present-but-
     * wrong-shape is an error rather than a silent default: the caller then
     * learns which field it got wrong instead of receiving a client it did not
     * describe.
     *
     * @param array<array-key, mixed> $metadata
     *
     * @return null|list<string>
     *
     * @throws ClientRegistrationFailed
     */
    private function optionalStringList(array $metadata, string $field): null|array
    {
        $value = $metadata[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_array($value) === false || array_is_list($value) === false) {
            throw ClientRegistrationFailed::invalidClientMetadata(sprintf(
                'The "%s" field must be an array of strings.',
                $field,
            ));
        }

        /** @var list<string> $strings */
        $strings = [];

        foreach ($value as $item) {
            if (is_string($item) === false) {
                throw ClientRegistrationFailed::invalidClientMetadata(sprintf(
                    'The "%s" field must contain only strings.',
                    $field,
                ));
            }

            $strings[] = $item;
        }

        return $strings;
    }
}
