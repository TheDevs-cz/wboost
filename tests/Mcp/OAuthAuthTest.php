<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingOAuthClient;

/**
 * The second credential on the `mcp` firewall (S8-T6): an OAuth 2.1 bearer
 * issued by this application's own authorization server, accepted ALONGSIDE the
 * personal access tokens {@see AuthTest} covers.
 *
 * Every token here is minted through the REAL endpoints (`/api/authorize` →
 * `/api/token`, PKCE and all — see {@see TestingOAuthClient}). Hand-forging a
 * JWT would test the resource server against itself and pass even if the issuer
 * had drifted; the contract worth proving is that a token this server ISSUES is
 * one it ACCEPTS.
 *
 * ## What this suite is actually guarding
 *
 * Not "OAuth works" — **equivalence**. A PAT-authenticated agent and an
 * OAuth-authenticated agent must be indistinguishable to voters, to
 * {@see \WBoost\Web\Mcp\Security\McpScopeChecker}, to
 * {@see \WBoost\Web\Mcp\Security\McpToolGate} and to `tools/list` filtering. So
 * the assertions are deliberately COMPARATIVE where they can be
 * ({@see testToolListingIsIdenticalForAPatAndAnOAuthTokenOfTheSameScope()}):
 * an expectation restated as a literal list would drift from
 * {@see ScopeFilteringTest} the day a tool is added, and would then be proving
 * nothing about the two paths agreeing.
 */
final class OAuthAuthTest extends WebTestCase
{
    /**
     * The happy path, asserted on IDENTITY: the probe tool reads the security
     * context from INSIDE the request the authenticator served, so a token that
     * resolved the wrong user (or no user) cannot pass a mere 200 check.
     *
     * The `sub` claim is the App User UUID, and this is what proves the
     * authenticator turns it back into the right row through `api_user_provider`
     * — the same row the PAT path reaches through `McpAccessToken::$user`.
     */
    public function testOAuthTokenAuthenticatesItsOwnUser(): void
    {
        $client = self::createClient();

        self::assertProbe(
            $client,
            self::oauthToken($client, [McpScope::TemplatesRead->value]),
            TestDataFixture::USER_1_EMAIL . '|' . McpScope::TemplatesRead->value,
        );
    }

    /**
     * The S1-T2 seam is fed from the JWT's `scopes` claim, and the IMPLICATION
     * closure is applied to it exactly as it is to a PAT's stored scopes: a
     * token granted only `templates:design` reports `templates:read` too.
     *
     * Worth its own test because the two halves live apart — the authenticator
     * copies raw strings, `McpScope::grants()` expands them — and a mapping
     * table sneaked into the authenticator would break this without breaking
     * anything else.
     */
    public function testImplicationExpansionAppliesToOAuthScopes(): void
    {
        $client = self::createClient();

        // The probe sorts, so `templates:design` precedes `templates:read`.
        self::assertProbe(
            $client,
            self::oauthToken($client, [McpScope::TemplatesDesign->value]),
            sprintf(
                '%s|%s,%s',
                TestDataFixture::USER_1_EMAIL,
                McpScope::TemplatesDesign->value,
                McpScope::TemplatesRead->value,
            ),
        );
    }

    /**
     * THE equivalence guarantee, stated as an equality rather than as a list:
     * the same scope, presented as a PAT and as an OAuth bearer, must produce
     * the SAME `tools/list`. Whatever `ScopeFilteringTest` pins down for PATs
     * therefore holds for OAuth by construction, and cannot drift.
     */
    public function testToolListingIsIdenticalForAPatAndAnOAuthTokenOfTheSameScope(): void
    {
        $client = self::createClient();

        $viaOAuth = self::listTools($client, self::oauthToken($client, [McpScope::TemplatesRead->value]));
        $viaPat = self::listTools($client, TestDataFixture::MCP_TOKEN_READ_ONLY);

        self::assertNotSame([], $viaPat, 'The PAT baseline listed no tools at all.');
        self::assertSame($viaPat, $viaOAuth);
    }

    /**
     * The other half of the gate: filtering is not an authorisation boundary, so
     * calling a filtered-out tool BY NAME with an OAuth token must be refused
     * with the same 403 and the same challenge a PAT gets — including the
     * missing scope's name, which is what lets an agent tell its user what to
     * re-authorize for.
     */
    public function testOAuthTokenHitsTheSameInsufficientScopeGate(): void
    {
        $client = self::createClient();

        $response = self::callTool(
            $client,
            self::oauthToken($client, [McpScope::TemplatesRead->value]),
            'scope_design_probe',
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $challenge = (string) $response->headers->get('WWW-Authenticate');
        self::assertStringContainsString('error="insufficient_scope"', $challenge);
        self::assertStringContainsString('scope="templates:design"', $challenge);
    }

    /**
     * ⚠️ The bug that would have been the worst possible outcome of this task.
     *
     * A `client_credentials` token for the REST API carries the legacy blanket
     * scope `api`, which is NOT an MCP scope. Presenting it at `/_mcp` must
     * grant NOTHING: an empty listing and a 403 on every call. It authenticates
     * (the client IS linked to a real user, so there is somebody for the voters
     * to decide about) and then reaches exactly zero tools.
     *
     * That falls out of the design rather than out of a special case:
     * {@see McpScope::fromStrings()} drops strings this release does not
     * understand, and `api` is one of them. Asserted anyway, because "silently
     * granting everything" is precisely the failure this test exists to catch.
     */
    public function testAnApiScopedClientCredentialsTokenGetsNoMcpScopes(): void
    {
        $client = self::createClient();

        $token = TestingOAuthClient::clientCredentialsToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        self::assertSame([], self::listTools($client, $token), 'An `api`-scoped token was shown MCP tools.');

        // Every tool, including the read tier a PAT of any scope can reach.
        foreach (['auth_probe', 'scope_read_probe', 'find_templates'] as $tool) {
            self::assertSame(
                Response::HTTP_FORBIDDEN,
                self::callTool($client, $token, $tool)->getStatusCode(),
                sprintf('An `api`-scoped token was allowed to call "%s".', $tool),
            );
        }
    }

    /**
     * The firewall's `UserChecker` belongs to the firewall, not to either
     * authenticator — so a never-activated (or deactivated) account is blocked
     * on the OAuth path too, even though its token is perfectly valid and its
     * scopes are perfectly real. Without this, an invitee who never confirmed
     * could authorize a connector and keep a door open the web login has shut.
     */
    public function testOAuthTokenOfAnUnconfirmedUserIsRejected(): void
    {
        $client = self::createClient();
        TestingOAuthClient::registerPublicClient($client->getContainer()->get(ClientManagerInterface::class));

        $token = TestingOAuthClient::accessToken(
            $client,
            TestDataFixture::INVITED_USER_EMAIL,
            [McpScope::TemplatesRead->value],
        );

        TestingMcpClient::initialize($client, $token);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    /**
     * A bearer that is not a PAT reaches the OAuth authenticator, fails league's
     * signature check, and comes back as the SAME {@see \WBoost\Web\Mcp\Security\McpChallenge}
     * 401 a bad PAT gets — never the bundle's plain-text response, and never a
     * 302 to the login form. A client must not have to work out which credential
     * type the server thought it was holding.
     */
    public function testGarbageBearerTokenGetsTheMcpChallenge(): void
    {
        $client = self::createClient();

        TestingMcpClient::initialize($client, 'eyJhbGciOiJSUzI1NiJ9.bm90LWEtdG9rZW4.bogus-signature');

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $challenge = (string) $response->headers->get('WWW-Authenticate');
        self::assertStringStartsWith('Bearer ', $challenge);
        self::assertStringContainsString('error="invalid_token"', $challenge);
        self::assertStringContainsString('resource_metadata="http://localhost/.well-known/oauth-protected-resource"', $challenge);
    }

    /**
     * A token the server itself has revoked stops working immediately — the
     * `/_mcp` counterpart of `AuthTest::testRevokedTokenIsRejected()`.
     *
     * This is the branch that makes an OAuth bearer killable at all. The JWT is
     * self-contained and its `exp` is an hour out (league's own `LooseValidAt`
     * constraint handles that half, on this very same failure path), so
     * revocation is the ONLY thing that can end a session early — and it works
     * because `persist_access_token` is on, which is what gives
     * `AccessTokenRepository::isAccessTokenRevoked()` a row to look at.
     */
    public function testRevokedOAuthTokenIsRejected(): void
    {
        $client = self::createClient();

        $token = self::oauthToken($client, [McpScope::TemplatesRead->value]);

        // It works before the revocation, so the assertion below is about the
        // revocation and not about a token that never worked.
        TestingMcpClient::initialize($client, $token);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $client->getContainer()->get(AccessTokenRepositoryInterface::class)
            ->revokeAccessToken(self::claim($token, 'jti'));

        TestingMcpClient::initialize($client, $token);

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', (string) $response->headers->get('WWW-Authenticate'));
    }

    /**
     * Registers the public client and mints an access token for USER_1 with the
     * given scopes, then drops the session cookie the flow left behind.
     *
     * Clearing the jar matters: `/_mcp` is a STATELESS firewall, so a lingering
     * `main` session must not be what makes the next assertion pass. Everything
     * after this call is carried by the bearer header alone.
     *
     * @param list<string> $scopes
     */
    private static function oauthToken(KernelBrowser $client, array $scopes): string
    {
        TestingOAuthClient::registerPublicClient($client->getContainer()->get(ClientManagerInterface::class));

        $token = TestingOAuthClient::accessToken($client, TestDataFixture::USER_1_EMAIL, $scopes);

        $client->getCookieJar()->clear();

        return $token;
    }

    /**
     * Calls `auth_probe`, which reports `<email>|<granted scopes>` read from the
     * live security context inside the request the authenticator served.
     */
    private static function assertProbe(KernelBrowser $client, string $token, string $expected): void
    {
        $response = self::callTool($client, $token, 'auth_probe');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $content = (string) $response->getContent();
        self::assertStringNotContainsString('"error"', $content);
        self::assertStringContainsString($expected, $content);
    }

    /**
     * @return list<string>
     */
    private static function listTools(KernelBrowser $client, string $token): array
    {
        $sessionId = TestingMcpClient::connect($client, $token);

        TestingMcpClient::request($client, 'tools/list', sessionId: $sessionId, token: $token);

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        $result = $payload['result'] ?? null;
        self::assertIsArray($result);

        $tools = $result['tools'] ?? null;
        self::assertIsArray($tools);

        /** @var list<string> $names */
        $names = [];

        foreach ($tools as $tool) {
            self::assertIsArray($tool);
            $name = $tool['name'] ?? null;
            self::assertIsString($name);
            $names[] = $name;
        }

        sort($names);

        return $names;
    }

    private static function callTool(KernelBrowser $client, string $token, string $tool): Response
    {
        $sessionId = TestingMcpClient::connect($client, $token);

        TestingMcpClient::request($client, 'tools/call', ['name' => $tool, 'arguments' => []], $sessionId, $token);

        return $client->getResponse();
    }

    /**
     * One claim of an unencrypted JWT, read without verifying the signature —
     * verification is the resource server's job and this suite exercises it
     * through the endpoint, not by re-implementing it here.
     */
    private static function claim(string $jwt, string $name): string
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

        $value = $claims[$name] ?? null;
        self::assertIsString($value);

        return $value;
    }
}
