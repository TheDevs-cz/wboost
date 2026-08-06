<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\OAuth2;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Tests\TestingOAuthClient;

/**
 * The OAuth 2.1 authorization-code flow (S8-T1) — the mechanism claude.ai and
 * ChatGPT connectors need, since they cannot send the custom header a personal
 * access token would ride in.
 *
 * Two wirings are load-bearing and neither shows up as an error anywhere else:
 *
 * - `/api/authorize` must be handled by the SESSION-backed `main` firewall.
 *   The bundle reads the resource owner straight off `Security::getUser()` and
 *   throws a RuntimeException if there is none, so a mis-scoped firewall turns
 *   "please log in" into a 500.
 * - PKCE must be REQUIRED for public clients. A public client is one that
 *   cannot keep a secret, so without a code challenge an intercepted redirect
 *   is a stolen token.
 */
final class AuthorizationEndpointTest extends WebTestCase
{
    private const string PUBLIC_CLIENT_ID = TestingOAuthClient::PUBLIC_CLIENT_ID;

    private const string REDIRECT_URI = TestingOAuthClient::REDIRECT_URI;

    private const string CODE_VERIFIER = TestingOAuthClient::CODE_VERIFIER;

    /**
     * Anonymous first contact is a REDIRECT TO THE LOGIN FORM, not an error
     * and not a bare 401 — the connector opens this URL in the user's browser,
     * so the Czech login page is the intended first screen.
     */
    public function testAnonymousVisitorIsSentToTheLoginForm(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery()));

        self::assertResponseRedirects('http://localhost/login');
    }

    /**
     * ...and comes back to the authorization request afterwards. This only
     * works because the firewall that handled the anonymous request is the one
     * named `main`: the ExceptionListener stores the target path under the
     * FIREWALL NAME, and the login form's success handler reads it back under
     * that same name. Routing `/api/authorize` through a differently-named
     * firewall would drop the user on the dashboard with the flow abandoned.
     */
    public function testTheAuthorizationUrlIsRememberedAcrossTheLogin(): void
    {
        $client = self::createClient();

        $query = self::authorizeQuery();
        $client->request('GET', '/api/authorize?' . http_build_query($query));

        $session = $client->getRequest()->getSession();
        $targetPath = $session->get('_security.main.target_path');

        self::assertIsString($targetPath);
        self::assertStringContainsString('/api/authorize', $targetPath);
        self::assertStringContainsString('code_challenge=', $targetPath);
    }

    /**
     * `require_code_challenge_for_public_clients` in action: a public client
     * that omits the challenge is refused before anything is issued.
     */
    public function testAPublicClientWithoutPkceIsRejected(): void
    {
        $client = self::createClient();
        self::registerPublicClient($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $query = self::authorizeQuery();
        unset($query['code_challenge'], $query['code_challenge_method']);

        $client->request('GET', '/api/authorize?' . http_build_query($query));

        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $payload = self::decode($client);
        self::assertSame('invalid_request', $payload['error'] ?? null);

        $hint = $payload['hint'] ?? null;
        self::assertIsString($hint);
        self::assertStringContainsString('code challenge', strtolower($hint));
    }

    /**
     * The happy path proves three things at once: the auth-code grant is
     * enabled at all, the logged-in user is resolved as the resource owner,
     * and the resolve listener runs — without it the bundle's default verdict
     * is DENIED and this would redirect with `error=access_denied`.
     *
     * Since S8-T5 the path runs THROUGH the consent screen (which
     * {@see ConsentTest} covers in its own right); `approveConsent()` is the
     * user clicking "Povolit přístup".
     */
    public function testAnAuthenticatedUserGetsAnAuthorizationCode(): void
    {
        $client = self::createClient();
        self::registerPublicClient($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery()));
        TestingOAuthClient::approveConsent($client);

        self::assertSame(Response::HTTP_FOUND, $client->getResponse()->getStatusCode());

        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::REDIRECT_URI . '?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

        self::assertArrayNotHasKey('error', $params);
        self::assertIsString($params['code'] ?? null);
        self::assertSame('opaque-state', $params['state'] ?? null);
    }

    /**
     * End to end: exchange the code at the very same `/api/token` endpoint the
     * client_credentials grant uses, and assert the token's `sub` is the App
     * User **UUID**.
     *
     * The UUID is the contract, not a detail. `api_user_provider` is an entity
     * provider on the `id` column, so a `sub` carrying the e-mail (the bundle's
     * default, {@see \WBoost\Web\Services\OAuth2\AppUserConverter} overrides it)
     * would not merely 401 — Postgres rejects a non-UUID literal against a
     * `uuid` column outright.
     */
    public function testTheCodeIsExchangedForATokenIdentifyingTheUser(): void
    {
        $client = self::createClient();
        self::registerPublicClient($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery()));
        TestingOAuthClient::approveConsent($client);
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $params);

        $code = $params['code'] ?? null;
        self::assertIsString($code);

        $client->request('POST', '/api/token', [
            'grant_type' => OAuth2Grants::AUTHORIZATION_CODE,
            'client_id' => self::PUBLIC_CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => self::CODE_VERIFIER,
        ]);

        self::assertSame(
            Response::HTTP_OK,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );

        $payload = self::decode($client);
        $accessToken = $payload['access_token'] ?? null;
        self::assertIsString($accessToken);

        self::assertSame(TestDataFixture::USER_1_ID, self::subjectOf($accessToken));
    }

    /**
     * The scope the client asked for is an {@see McpScope} value, which the
     * authorization server only knows because
     * `config/packages/league_oauth2_server.php` derives its `available` list
     * from that enum. An unregistered scope comes back as `invalid_scope`, so
     * this is the drift alarm for the registration side of the scope contract.
     */
    public function testAnMcpScopeSurvivesTheWholeFlow(): void
    {
        $client = self::createClient();
        self::registerPublicClient($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery()));
        TestingOAuthClient::approveConsent($client);
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $params);

        self::assertArrayNotHasKey('error', $params, 'The requested MCP scope was refused by the authorization server.');

        $client->request('POST', '/api/token', [
            'grant_type' => OAuth2Grants::AUTHORIZATION_CODE,
            'client_id' => self::PUBLIC_CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $params['code'] ?? '',
            'code_verifier' => self::CODE_VERIFIER,
        ]);

        $payload = self::decode($client);
        $accessToken = $payload['access_token'] ?? null;
        self::assertIsString($accessToken);

        // League does not echo `scope` in the token response body (it only does
        // so when the granted scopes differ from the request), so the granted
        // set is read where the resource server reads it: the JWT's `scopes`
        // claim, which is exactly what the future MCP authenticator will gate on.
        self::assertSame([McpScope::TemplatesRead->value], self::claim($accessToken, 'scopes'));
    }

    /**
     * The refresh-token decision of S8-T6, asserted from both ends.
     *
     * League ISSUES a refresh token with every authorization-code exchange
     * whether or not the grant is enabled — the flag only governs redemption.
     * So before S8-T6 this response already carried a `refresh_token` the token
     * endpoint would answer `unsupported_grant_type` to, and an hour-old
     * connector had to re-run the whole browser flow. The fix was to enable the
     * grant; this test is what stops it being quietly turned off again, leaving
     * the broken advertisement behind.
     *
     * The second half asserts ROTATION (`revoke_refresh_tokens`, on by default),
     * which is what bounds the risk of handing a long-lived credential to a
     * PUBLIC client: redeeming a refresh token invalidates it, so a stolen copy
     * is single-use and a replay fails.
     */
    public function testTheIssuedRefreshTokenCanBeRedeemedAndIsRotated(): void
    {
        $client = self::createClient();
        self::registerPublicClient($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery()));
        TestingOAuthClient::approveConsent($client);
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $params);

        $payload = TestingOAuthClient::exchange($client, [
            'grant_type' => OAuth2Grants::AUTHORIZATION_CODE,
            'client_id' => self::PUBLIC_CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => is_string($params['code'] ?? null) ? $params['code'] : '',
            'code_verifier' => self::CODE_VERIFIER,
        ]);

        $refreshToken = $payload['refresh_token'] ?? null;
        self::assertIsString($refreshToken, 'The authorization-code response carried no refresh token.');

        $refreshed = TestingOAuthClient::exchange($client, [
            'grant_type' => OAuth2Grants::REFRESH_TOKEN,
            'client_id' => self::PUBLIC_CLIENT_ID,
            'refresh_token' => $refreshToken,
        ]);

        self::assertSame(
            Response::HTTP_OK,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );

        $accessToken = $refreshed['access_token'] ?? null;
        self::assertIsString($accessToken);

        // The refreshed token still names the same user and the same scopes —
        // a refresh must never widen (or lose) what was consented to.
        self::assertSame(TestDataFixture::USER_1_ID, self::subjectOf($accessToken));
        self::assertSame([McpScope::TemplatesRead->value], self::claim($accessToken, 'scopes'));

        // Rotation: the presented refresh token was revoked by its own use, so
        // a replay is `invalid_grant` (league's 400 for a refresh token that
        // does not decrypt, is expired, or has been revoked).
        $replayed = TestingOAuthClient::exchange($client, [
            'grant_type' => OAuth2Grants::REFRESH_TOKEN,
            'client_id' => self::PUBLIC_CLIENT_ID,
            'refresh_token' => $refreshToken,
        ]);

        self::assertSame(
            Response::HTTP_BAD_REQUEST,
            $client->getResponse()->getStatusCode(),
            'A refresh token survived being redeemed — rotation is off.',
        );
        self::assertSame('invalid_grant', $replayed['error'] ?? null);
        self::assertArrayNotHasKey('access_token', $replayed);
    }

    /**
     * @return array<string, string>
     */
    private static function authorizeQuery(): array
    {
        return TestingOAuthClient::authorizeQuery([McpScope::TemplatesRead->value]);
    }

    private static function registerPublicClient(KernelBrowser $browser): void
    {
        TestingOAuthClient::registerPublicClient($browser->getContainer()->get(ClientManagerInterface::class));
    }

    private static function subjectOf(string $jwt): string
    {
        $subject = self::claim($jwt, 'sub');
        self::assertIsString($subject);

        return $subject;
    }

    /**
     * One claim of an unencrypted JWT, read without verifying the signature —
     * verification is the resource server's job and the API suite exercises it.
     */
    private static function claim(string $jwt, string $name): mixed
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);

        $claims = json_decode(
            (string) base64_decode(strtr($parts[1], '-_', '+/'), true),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($claims);

        return $claims[$name] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(KernelBrowser $browser): array
    {
        $payload = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
