<?php

declare(strict_types=1);

namespace WBoost\Web\Tests;

use JsonException;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client as OAuth2Client;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use WBoost\Web\Mcp\Security\McpScope;

/**
 * Drives the OAuth 2.1 authorization-code flow end to end, through the REAL
 * endpoints — `/api/authorize` then `/api/token`, PKCE included.
 *
 * Every consumer of an OAuth token in the test suite mints it here rather than
 * hand-forging a JWT, because the contract worth testing is that **a token this
 * server actually issues is one it actually accepts**. A forged token would
 * happily pass a resource server that had drifted from the issuer.
 *
 * The client registration is shared for the same reason: its GRANTS and SCOPES
 * are the two settings that silently break the flow at a distance (a missing
 * scope surfaces as `invalid_scope` only at the token exchange; a missing grant
 * only when a refresh token is redeemed), so there is exactly one place that
 * decides what an MCP connector's client looks like.
 */
readonly final class TestingOAuthClient
{
    /** Exactly 32 chars — `oauth2_client.identifier` is `varchar(32)`. */
    public const string PUBLIC_CLIENT_ID = 'mcptestpublicclientxxxxxxxxxxxxx';

    public const string REDIRECT_URI = 'http://localhost/oauth/callback';

    /**
     * The RFC 7636 verifier of the pair used throughout; the `S256` challenge is
     * computed from it ({@see codeChallenge()}) rather than pasted, so the two
     * can never drift.
     */
    public const string CODE_VERIFIER = 'wboost-test-code-verifier-0123456789-abcdefghijklmnop';

    /**
     * A PUBLIC client (no secret) with a registered redirect URI — the shape
     * every MCP connector has, and the shape PKCE is mandatory for. Created per
     * test rather than in the shared fixture so the client_credentials fixtures
     * stay exactly as the API suite expects them; DAMA rolls the row back.
     *
     * The explicit `setScopes()` is LOAD-BEARING, and it is the trap any future
     * client-registration code (S8-T4) has to avoid: the bundle's
     * `AddClientDefaultScopesListener` stamps `league_oauth2_server.scopes.default`
     * — i.e. the legacy `api` scope — onto every client saved without scopes of
     * its own, and `ScopeRepository::setupScopes` then refuses anything outside
     * that list. A client registered without the MCP scopes therefore sails
     * through the authorize step and only fails when the code is exchanged, with
     * `invalid_scope`.
     *
     * `refresh_token` is in the grants for the mirror-image reason: league
     * issues a refresh token with every auth-code exchange, but
     * `ClientRepository::validateClient()` refuses to REDEEM one unless the
     * client also names that grant. Registration code that lists only
     * `authorization_code` hands out a credential its own client can never use.
     *
     * The manager is passed IN rather than pulled off the browser's container:
     * it is a private service, and phpstan-symfony only waives that rule inside
     * a `KernelTestCase` subclass — which this helper deliberately is not.
     */
    public static function registerPublicClient(ClientManagerInterface $manager): void
    {
        $client = new OAuth2Client('mcp-test-public', self::PUBLIC_CLIENT_ID, null);
        $client->setActive(true);
        $client->setGrants(
            new Grant(OAuth2Grants::AUTHORIZATION_CODE),
            new Grant(OAuth2Grants::REFRESH_TOKEN),
        );
        $client->setRedirectUris(new RedirectUri(self::REDIRECT_URI));
        $client->setScopes(...array_map(
            static fn (McpScope $scope): Scope => new Scope($scope->value),
            McpScope::cases(),
        ));

        $manager->save($client);
    }

    /**
     * The full authorization-code flow for an already-registered public client:
     * log the user in, visit `/api/authorize`, follow the redirect's `code` to
     * `/api/token`. Returns the access token.
     *
     * @param list<string> $scopes
     */
    public static function accessToken(KernelBrowser $browser, string $email, array $scopes): string
    {
        TestingLogin::logInAsUser($browser, $email);

        $browser->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery($scopes)));
        self::approveConsent($browser);

        $location = (string) $browser->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

        $code = $params['code'] ?? null;

        if (!is_string($code)) {
            throw new LogicException(sprintf(
                'The authorization endpoint returned no code (HTTP %d): %s',
                $browser->getResponse()->getStatusCode(),
                $location !== '' ? $location : (string) $browser->getResponse()->getContent(),
            ));
        }

        $payload = self::exchange($browser, [
            'grant_type' => OAuth2Grants::AUTHORIZATION_CODE,
            'client_id' => self::PUBLIC_CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => self::CODE_VERIFIER,
        ]);

        $accessToken = $payload['access_token'] ?? null;

        if (!is_string($accessToken)) {
            throw new LogicException('The token endpoint returned no access_token: ' . json_encode($payload));
        }

        return $accessToken;
    }

    /**
     * Clicks through the S8-T5 consent interstitial, if it was shown.
     *
     * Every token in the suite is minted through the REAL endpoints, and since
     * S8-T5 the real flow has THREE legs, not one: `/api/authorize` parks the
     * request and redirects to `/oauth/consent`, the user answers, and the
     * browser goes back to `/api/authorize` — which is where the code finally
     * appears. Callers therefore see the same "read the Location header" shape
     * as before, with this in between.
     *
     * It is a NO-OP when no consent screen appeared, which is not laziness: a
     * remembered approval legitimately skips the prompt, so a helper that
     * insisted on the screen would fail the second authorization of the same
     * client — the exact behaviour the feature exists to provide.
     *
     * The form is submitted through the DomCrawler, so the real CSRF token
     * rides along; hand-POSTing would silently test a path production does not
     * have.
     */
    public static function approveConsent(KernelBrowser $browser): void
    {
        self::answerConsent($browser, 'approve');
    }

    /**
     * The refusal half of {@see approveConsent()} — same three legs, "Odmítnout"
     * instead. The response afterwards is the redirect league builds for a
     * DENIED authorization, i.e. `error=access_denied` at the client's own
     * redirect URI.
     */
    public static function denyConsent(KernelBrowser $browser): void
    {
        self::answerConsent($browser, 'deny');
    }

    private static function answerConsent(KernelBrowser $browser, string $button): void
    {
        $location = (string) $browser->getResponse()->headers->get('Location');

        if (!str_contains($location, '/oauth/consent')) {
            return;
        }

        $crawler = $browser->followRedirect();

        $browser->submit($crawler->selectButton($button)->form());

        // POST /oauth/consent answers with a redirect back to /api/authorize;
        // following it leaves the browser holding the client redirect, exactly
        // as an uninterrupted flow would.
        $browser->followRedirect();
    }

    /**
     * A client_credentials token — the REST API's grant, whose default scope is
     * the legacy blanket `api`. Used to prove such a token gets NO MCP scopes.
     */
    public static function clientCredentialsToken(KernelBrowser $browser, string $clientId, string $clientSecret): string
    {
        $payload = self::exchange($browser, [
            'grant_type' => OAuth2Grants::CLIENT_CREDENTIALS,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        $accessToken = $payload['access_token'] ?? null;

        if (!is_string($accessToken)) {
            throw new LogicException('The token endpoint returned no access_token: ' . json_encode($payload));
        }

        return $accessToken;
    }

    /**
     * POSTs to `/api/token` and decodes the response.
     *
     * @param array<string, string> $parameters
     *
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public static function exchange(KernelBrowser $browser, array $parameters): array
    {
        $browser->request('POST', '/api/token', $parameters);

        $payload = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($payload)) {
            throw new LogicException('The token endpoint did not answer with a JSON object.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param list<string> $scopes
     *
     * @return array<string, string>
     */
    public static function authorizeQuery(array $scopes): array
    {
        return [
            'response_type' => 'code',
            'client_id' => self::PUBLIC_CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => implode(' ', $scopes),
            'state' => 'opaque-state',
            'code_challenge' => self::codeChallenge(),
            'code_challenge_method' => 'S256',
        ];
    }

    public static function codeChallenge(): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', self::CODE_VERIFIER, true)), '+/', '-_'), '=');
    }
}
