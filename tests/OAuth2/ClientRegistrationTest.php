<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\OAuth2;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\ClientInterface;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Controller\OAuth2\AuthorizationServerMetadataController;
use WBoost\Web\Controller\OAuth2\ClientRegistrationController;
use WBoost\Web\Exceptions\ClientRegistrationFailed;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Services\OAuth2\DynamicClientRegistrar;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Mcp\TestingMcpClient;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Tests\TestingOAuthClient;

/**
 * RFC 7591 dynamic client registration (S8-T4) — the endpoint that would let a
 * claude.ai / ChatGPT connector introduce itself instead of an operator running
 * `app:oauth-client:create`.
 *
 * ## The one test that matters
 *
 * {@see testARegisteredClientCanCompleteTheWholeFlowAndReachTheMcpEndpoint()}.
 * Registration has two failure modes that a 201 cannot see, both of which
 * surface hours later and far away:
 *
 * - a client saved without explicit scopes is stamped with the bundle's
 *   DEFAULT scope (`api`), and then fails at the TOKEN EXCHANGE with
 *   `invalid_scope` — after `/api/authorize` has already said yes;
 * - a client registered with `authorization_code` alone is handed a refresh
 *   token league will not let it redeem, so it works for exactly one hour.
 *
 * Only driving register → authorize → token → refresh → `/_mcp` proves neither
 * happened, which is why that test does all five.
 *
 * ## ⚠️ The endpoint is DISABLED outside the test environment
 *
 * `.env` sets `OAUTH2_DYNAMIC_CLIENT_REGISTRATION=0` and must keep doing so
 * until the consent screen (S8-T5) exists — open registration composed with
 * today's auto-approving `/api/authorize` is a one-click account takeover. The
 * suite runs with it on because the acceptance test needs the enabled state;
 * {@see testTheEndpointDoesNotExistWhileTheFlagIsOff()} covers the other one.
 */
final class ClientRegistrationTest extends WebTestCase
{
    private const string REGISTRATION_PATH = ClientRegistrationController::REGISTRATION_PATH;

    /** What a real connector registers: an https callback on its own origin. */
    private const string CONNECTOR_REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';

    /**
     * RFC 7591 §3.2.1, and every field of it is load-bearing to a caller: `201`
     * (not 200) is how a client knows the registration was created, `no-store`
     * is required because the body carries a credential-shaped identifier, and
     * the ABSENCE of `client_secret` is what tells the client it is public and
     * must use PKCE.
     */
    public function testAValidRegistrationReturnsAPublicClient(): void
    {
        $client = self::createClient();

        $registration = self::register($client, [
            'client_name' => 'Claude',
            'redirect_uris' => [self::CONNECTOR_REDIRECT_URI],
        ]);

        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $clientId = $registration['client_id'] ?? null;
        self::assertIsString($clientId);
        self::assertNotSame('', $clientId);

        self::assertArrayNotHasKey('client_secret', $registration);
        self::assertArrayNotHasKey('client_secret_expires_at', $registration);

        self::assertSame('Claude', $registration['client_name'] ?? null);
        self::assertSame([self::CONNECTOR_REDIRECT_URI], $registration['redirect_uris'] ?? null);
        self::assertSame('none', $registration['token_endpoint_auth_method'] ?? null);
        self::assertSame(['code'], $registration['response_types'] ?? null);
        self::assertIsInt($registration['client_id_issued_at'] ?? null);
    }

    /**
     * `oauth2_client.identifier` is the table's PRIMARY KEY and it is
     * `VARCHAR(32)` — hard-coded in the bundle's mapping driver. A minted id
     * one character over would not fail here; it would fail on INSERT, in
     * production, on the first connector that ever tried to register.
     *
     * The `dcr_` prefix is asserted because it is what makes machine-registered
     * clients findable — for `app:oauth-client:list`, and for the "expire
     * clients that never completed a flow" job that has to exist before the
     * flag is flipped.
     */
    public function testTheMintedIdentifierFitsTheColumnAndIsMarkedAsDynamic(): void
    {
        $client = self::createClient();

        $clientId = self::registeredClientId($client);

        self::assertSame(32, strlen($clientId));
        self::assertStringStartsWith('dcr_', $clientId);
    }

    /**
     * **THE acceptance test.** A client that registered itself completes the
     * entire flow it was registered for, and the token it ends up with works at
     * the endpoint the whole feature exists to reach.
     *
     * Read the class docblock for what each leg is guarding: the token exchange
     * catches the default-scope trap, the refresh catches the missing-grant
     * trap, and `/_mcp` catches everything in between being wired to a client
     * the resource server does not accept.
     */
    public function testARegisteredClientCanCompleteTheWholeFlowAndReachTheMcpEndpoint(): void
    {
        $browser = self::createClient();

        $clientId = self::registeredClientId($browser);

        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $code = self::authorizationCode($browser, $clientId, [McpScope::TemplatesRead->value]);

        $tokens = TestingOAuthClient::exchange($browser, [
            'grant_type' => OAuth2Grants::AUTHORIZATION_CODE,
            'client_id' => $clientId,
            'redirect_uri' => self::CONNECTOR_REDIRECT_URI,
            'code' => $code,
            'code_verifier' => TestingOAuthClient::CODE_VERIFIER,
        ]);

        self::assertSame(
            Response::HTTP_OK,
            $browser->getResponse()->getStatusCode(),
            'The self-registered client could not exchange its code: ' . (string) $browser->getResponse()->getContent(),
        );

        $accessToken = $tokens['access_token'] ?? null;
        self::assertIsString($accessToken);

        // Trap 2: league issues this whether or not the grant was registered,
        // but only redeems it if the CLIENT names the grant.
        $refreshToken = $tokens['refresh_token'] ?? null;
        self::assertIsString($refreshToken);

        // `/_mcp` is a stateless firewall; drop the `main` session so the
        // bearer is provably the only thing carrying this request.
        $browser->getCookieJar()->clear();

        self::assertMcpAcceptsTheToken($browser, $accessToken);

        // ORDER MATTERS: redeeming the refresh token REVOKES the access token
        // it was issued beside (league's RefreshTokenGrant revokes the previous
        // pair), so the check above has to happen first and the check below has
        // to use the NEW token. Doing it the other way round produces a 401
        // that looks like an authenticator bug and is not one.
        $refreshed = TestingOAuthClient::exchange($browser, [
            'grant_type' => OAuth2Grants::REFRESH_TOKEN,
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
        ]);

        self::assertSame(
            Response::HTTP_OK,
            $browser->getResponse()->getStatusCode(),
            'A self-registered client was handed a refresh token it cannot redeem: '
            . (string) $browser->getResponse()->getContent(),
        );

        $refreshedAccessToken = $refreshed['access_token'] ?? null;
        self::assertIsString($refreshedAccessToken);

        // An hour into a connector's life this is the token it is holding, so
        // it has to reach `/_mcp` too — otherwise the flow works exactly once.
        self::assertMcpAcceptsTheToken($browser, $refreshedAccessToken);
    }

    /**
     * A full MCP handshake plus a `tools/list`, carried by the bearer alone.
     *
     * `connect()` already throws when the token is refused (an unauthenticated
     * `initialize` comes back without an `Mcp-Session-Id`), and the listing is
     * what proves the token reached a real, scoped tool surface rather than
     * merely getting past the firewall.
     */
    private static function assertMcpAcceptsTheToken(KernelBrowser $browser, string $token): void
    {
        $sessionId = TestingMcpClient::connect($browser, $token);

        TestingMcpClient::request($browser, 'tools/list', sessionId: $sessionId, token: $token);

        self::assertSame(
            Response::HTTP_OK,
            $browser->getResponse()->getStatusCode(),
            'The token a self-registered client obtained was refused at /_mcp: '
            . (string) $browser->getResponse()->getContent(),
        );

        self::assertIsArray(self::decode($browser)['result'] ?? null);
    }

    /**
     * Trap 1, asserted on the stored row rather than only through the flow
     * above, so the diagnosis is one assertion away when it breaks.
     *
     * The bundle's `AddClientDefaultScopesListener` stamps
     * `league_oauth2_server.scopes.default` — the legacy blanket `api` scope of
     * the REST API — onto every client saved WITHOUT scopes of its own. A
     * dynamically registered client carrying `api` would be a self-service
     * route to the whole REST API.
     */
    public function testTheRegisteredClientCarriesMcpScopesAndNeverTheBlanketApiScope(): void
    {
        $browser = self::createClient();

        $registered = self::storedClient($browser, self::registeredClientId($browser));

        $scopes = array_map(strval(...), $registered->getScopes());

        self::assertEqualsCanonicalizing(McpScope::values(), $scopes);
        self::assertNotContains('api', $scopes);
    }

    /**
     * The drift guard between the two places that decide what a connector's
     * client looks like: this endpoint, and
     * {@see TestingOAuthClient::registerPublicClient()} — which the rest of the
     * suite treats as the reference shape and whose docblock documents both
     * traps. If they ever disagree, one of them is wrong.
     */
    public function testARegisteredClientHasTheSameShapeAsTheSuiteReferenceClient(): void
    {
        $browser = self::createClient();

        $manager = $browser->getContainer()->get(ClientManagerInterface::class);
        TestingOAuthClient::registerPublicClient($manager);

        $reference = self::storedClient($browser, TestingOAuthClient::PUBLIC_CLIENT_ID);
        $registered = self::storedClient($browser, self::registeredClientId($browser));

        self::assertEqualsCanonicalizing(
            array_map(strval(...), $reference->getGrants()),
            array_map(strval(...), $registered->getGrants()),
        );
        self::assertEqualsCanonicalizing(
            array_map(strval(...), $reference->getScopes()),
            array_map(strval(...), $registered->getScopes()),
        );
        self::assertNull($registered->getSecret(), 'A dynamically registered client must be public.');
        self::assertTrue($registered->isActive());
    }

    /**
     * A native client (Claude Code, an MCP CLI) receives its callback on a port
     * it opened on the user's own machine, where https is not available. This
     * is THE documented exception to the https rule — RFC 8252 §7.3 — and it is
     * the reason "one command installation" works at all.
     */
    public function testLoopbackHttpIsTheDocumentedExceptionForNativeClients(): void
    {
        $browser = self::createClient();

        foreach (['http://127.0.0.1:41999/callback', 'http://localhost:8123/cb', 'http://[::1]:9/cb'] as $redirectUri) {
            $registration = self::register($browser, ['redirect_uris' => [$redirectUri]]);

            self::assertSame(
                Response::HTTP_CREATED,
                $browser->getResponse()->getStatusCode(),
                sprintf('Loopback redirect URI "%s" was refused.', $redirectUri),
            );
            self::assertSame([$redirectUri], $registration['redirect_uris'] ?? null);
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    #[DataProvider('rejectedRegistrations')]
    public function testARejectedRegistrationAnswersWithTheRfcErrorCode(array $metadata, string $expectedError): void
    {
        $browser = self::createClient();

        $payload = self::register($browser, $metadata);

        self::assertSame(
            Response::HTTP_BAD_REQUEST,
            $browser->getResponse()->getStatusCode(),
            'Expected a refusal, got: ' . (string) $browser->getResponse()->getContent(),
        );
        self::assertSame($expectedError, $payload['error'] ?? null);
        self::assertIsString($payload['error_description'] ?? null);
        self::assertArrayNotHasKey('client_id', $payload);
    }

    /**
     * Every way a registration can be refused, with the RFC 7591 §3.2.2 code a
     * conformant client uses to decide what to fix. The split matters: an
     * `invalid_redirect_uri` tells the client to change ONE field, while
     * `invalid_client_metadata` tells it the request was wrong.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function rejectedRegistrations(): iterable
    {
        $ok = ['redirect_uris' => ['https://claude.ai/cb']];

        yield 'plain http on a public host' => [
            ['redirect_uris' => ['http://claude.ai/cb']],
            ClientRegistrationFailed::INVALID_REDIRECT_URI,
        ];

        yield 'a wildcard host' => [
            ['redirect_uris' => ['https://*.claude.ai/cb']],
            ClientRegistrationFailed::INVALID_REDIRECT_URI,
        ];

        // The bundle stores every redirect URI of a client SPACE-DELIMITED in
        // one TEXT column, so a value carrying a space would come back as two
        // registered URIs — one of which nothing validated.
        yield 'a second URI smuggled in behind a space' => [
            ['redirect_uris' => ['https://claude.ai/cb https://evil.example/cb']],
            ClientRegistrationFailed::INVALID_REDIRECT_URI,
        ];

        yield 'a fragment' => [
            ['redirect_uris' => ['https://claude.ai/cb#token']],
            ClientRegistrationFailed::INVALID_REDIRECT_URI,
        ];

        yield 'userinfo that disguises the real host' => [
            ['redirect_uris' => ['https://claude.ai@evil.example/cb']],
            ClientRegistrationFailed::INVALID_REDIRECT_URI,
        ];

        yield 'a private-use scheme' => [
            ['redirect_uris' => ['com.evil.app:/callback']],
            ClientRegistrationFailed::INVALID_REDIRECT_URI,
        ];

        yield 'a relative URI' => [
            ['redirect_uris' => ['/callback']],
            ClientRegistrationFailed::INVALID_REDIRECT_URI,
        ];

        yield 'more redirect URIs than a real client needs' => [
            ['redirect_uris' => array_map(
                static fn (int $i): string => sprintf('https://claude.ai/cb/%d', $i),
                range(1, 6),
            )],
            ClientRegistrationFailed::INVALID_REDIRECT_URI,
        ];

        yield 'no redirect URI at all' => [
            ['client_name' => 'Claude'],
            ClientRegistrationFailed::INVALID_CLIENT_METADATA,
        ];

        yield 'an empty redirect URI list' => [
            ['redirect_uris' => []],
            ClientRegistrationFailed::INVALID_CLIENT_METADATA,
        ];

        yield 'redirect URIs that are not strings' => [
            ['redirect_uris' => [['https://claude.ai/cb']]],
            ClientRegistrationFailed::INVALID_CLIENT_METADATA,
        ];

        // The blanket scope of the in-production client_credentials REST API.
        // It IS registered with the authorization server, so only the ceiling
        // stops a self-registered client from asking for it.
        yield 'the legacy blanket api scope' => [
            $ok + ['scope' => 'api'],
            ClientRegistrationFailed::INVALID_CLIENT_METADATA,
        ];

        yield 'an invented scope' => [
            $ok + ['scope' => 'templates:read templates:delete'],
            ClientRegistrationFailed::INVALID_CLIENT_METADATA,
        ];

        // The grant that issues a token with no user in the loop.
        yield 'the client_credentials grant' => [
            $ok + ['grant_types' => ['authorization_code', 'client_credentials']],
            ClientRegistrationFailed::INVALID_CLIENT_METADATA,
        ];

        yield 'the implicit grant' => [
            $ok + ['grant_types' => ['implicit']],
            ClientRegistrationFailed::INVALID_CLIENT_METADATA,
        ];

        yield 'a response type other than code' => [
            $ok + ['response_types' => ['token']],
            ClientRegistrationFailed::INVALID_CLIENT_METADATA,
        ];

        // Confidential clients are not registered: a secret minted for an
        // anonymous caller is a credential we then have to protect, and it
        // makes PKCE optional.
        yield 'a request for a confidential client' => [
            $ok + ['token_endpoint_auth_method' => 'client_secret_basic'],
            ClientRegistrationFailed::INVALID_CLIENT_METADATA,
        ];

        yield 'a software statement we cannot verify' => [
            $ok + ['software_statement' => 'eyJhbGciOiJub25lIn0.e30.'],
            ClientRegistrationFailed::INVALID_SOFTWARE_STATEMENT,
        ];
    }

    /**
     * A body that is not a JSON object at all — the first thing a fuzzer sends.
     */
    public function testANonObjectBodyIsRefusedAsInvalidMetadata(): void
    {
        $browser = self::createClient();

        foreach (['', 'not json', '"a string"', '[1,2,3]'] as $body) {
            $browser->request(
                'POST',
                self::REGISTRATION_PATH,
                server: ['CONTENT_TYPE' => 'application/json'],
                content: $body,
            );

            self::assertSame(
                Response::HTTP_BAD_REQUEST,
                $browser->getResponse()->getStatusCode(),
                sprintf('Body %s was not refused.', var_export($body, true)),
            );
            self::assertSame(
                ClientRegistrationFailed::INVALID_CLIENT_METADATA,
                self::decode($browser)['error'] ?? null,
            );
        }
    }

    /**
     * ⚠️ **The SSRF contract.** RFC 7591 metadata carries URL-valued fields
     * supplied by an unauthenticated caller. This server NEVER dereferences
     * one — it has no HTTP client on this path at all — so the addresses below,
     * every one of which names a service on the internal docker network or a
     * cloud metadata endpoint, are simply ignored.
     *
     * The assertion is on the RESPONSE: the fields do not come back. That is
     * the observable half of "not stored, not resolved, not echoed", and it is
     * what stops a caller believing a field it sent was accepted. If somebody
     * later adds fetching (for Client ID Metadata Documents, say), this test is
     * where the SSRF guard has to be justified.
     */
    public function testUrlValuedMetadataIsIgnoredAndNeverFetched(): void
    {
        $browser = self::createClient();

        $registration = self::register($browser, [
            'redirect_uris' => [self::CONNECTOR_REDIRECT_URI],
            'logo_uri' => 'http://gotenberg:3000/health',
            'client_uri' => 'http://169.254.169.254/latest/meta-data/',
            'policy_uri' => 'http://127.0.0.1:9000/minio',
            'tos_uri' => 'file:///etc/passwd',
            'jwks_uri' => 'http://postgres:5432/',
        ]);

        self::assertSame(Response::HTTP_CREATED, $browser->getResponse()->getStatusCode());

        foreach (['logo_uri', 'client_uri', 'policy_uri', 'tos_uri', 'jwks_uri', 'jwks'] as $field) {
            self::assertArrayNotHasKey(
                $field,
                $registration,
                sprintf('"%s" was echoed back, which claims it was registered.', $field),
            );
        }
    }

    /**
     * The metadata document and the endpoint are driven by ONE flag, so a
     * client can never be told to register somewhere that 404s. This asserts
     * the ENABLED half; {@see testTheEndpointDoesNotExistWhileTheFlagIsOff()}
     * asserts the other one against the same document.
     */
    public function testTheMetadataDocumentAdvertisesTheEndpointWhileItIsEnabled(): void
    {
        $browser = self::createClient();

        $browser->request('GET', AuthorizationServerMetadataController::METADATA_PATH);

        self::assertSame(
            'http://localhost' . self::REGISTRATION_PATH,
            self::decode($browser)['registration_endpoint'] ?? null,
        );
    }

    /**
     * The production posture, asserted rather than assumed.
     *
     * `.env` ships `OAUTH2_DYNAMIC_CLIENT_REGISTRATION=0`, and while it is off
     * the endpoint must be gone AND unadvertised — RFC 8414 reads an absent
     * `registration_endpoint` as "not supported", so a conformant client falls
     * back to an operator-issued `client_id` instead of failing.
     *
     * 404 rather than 403 on purpose: there is nothing here to be forbidden
     * from.
     */
    public function testTheEndpointDoesNotExistWhileTheFlagIsOff(): void
    {
        self::withRegistrationDisabled(static function (): void {
            $browser = self::createClient();

            $browser->request(
                'POST',
                self::REGISTRATION_PATH,
                server: ['CONTENT_TYPE' => 'application/json'],
                content: (string) json_encode(['redirect_uris' => [self::CONNECTOR_REDIRECT_URI]]),
            );

            self::assertSame(Response::HTTP_NOT_FOUND, $browser->getResponse()->getStatusCode());

            $browser->request('GET', AuthorizationServerMetadataController::METADATA_PATH);

            self::assertArrayNotHasKey('registration_endpoint', self::decode($browser));
        });
    }

    /**
     * The limiter is not a security boundary — an attacker with addresses to
     * spare walks around a per-IP limit — but an unauthenticated endpoint that
     * INSERTs a row must not be usable to fill the table, and that abuse
     * survives even after the consent screen lands.
     *
     * `disableReboot()` is what makes this observable: the test environment
     * stores limiter state in an `array` pool (see config/packages/test/cache.php)
     * so tokens cannot leak between tests, and an array pool lives and dies with
     * its kernel. Without this the counter would reset on every request and the
     * test would pass while proving nothing.
     */
    public function testRegistrationIsRateLimitedPerClientAddress(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();

        $accepted = 0;

        for ($attempt = 0; $attempt < 12; $attempt++) {
            // Deliberately NOT self::register(): a 429 is produced by the
            // kernel's error handling, not by this endpoint, so its body is not
            // the RFC 7591 JSON and must not be decoded as such.
            $browser->request(
                'POST',
                self::REGISTRATION_PATH,
                server: ['CONTENT_TYPE' => 'application/json'],
                content: (string) json_encode(['redirect_uris' => [sprintf('https://claude.ai/cb/%d', $attempt)]]),
            );

            $status = $browser->getResponse()->getStatusCode();

            if ($status === Response::HTTP_TOO_MANY_REQUESTS) {
                break;
            }

            self::assertSame(Response::HTTP_CREATED, $status, (string) $browser->getResponse()->getContent());
            $accepted++;
        }

        self::assertSame(
            Response::HTTP_TOO_MANY_REQUESTS,
            $browser->getResponse()->getStatusCode(),
            'Twelve registrations from one address were all accepted — the limiter is not wired.',
        );
        self::assertSame(10, $accepted, 'The limiter did not stop at the configured limit.');
        self::assertNotNull($browser->getResponse()->headers->get('Retry-After'));
    }

    /**
     * Runs `$body` with the feature flag forced off.
     *
     * Symfony resolves `%env(...)%` placeholders at RUNTIME, per container
     * instance, so overriding the variable and booting a fresh kernel inside is
     * enough — no separate environment is needed. Both superglobals are set
     * because the env-var processor reads `$_ENV` first and `$_SERVER` second.
     */
    private static function withRegistrationDisabled(callable $body): void
    {
        $previousEnv = $_ENV['OAUTH2_DYNAMIC_CLIENT_REGISTRATION'] ?? null;
        $previousServer = $_SERVER['OAUTH2_DYNAMIC_CLIENT_REGISTRATION'] ?? null;

        self::ensureKernelShutdown();

        $_ENV['OAUTH2_DYNAMIC_CLIENT_REGISTRATION'] = '0';
        $_SERVER['OAUTH2_DYNAMIC_CLIENT_REGISTRATION'] = '0';

        try {
            $body();
        } finally {
            self::ensureKernelShutdown();

            if ($previousEnv === null) {
                unset($_ENV['OAUTH2_DYNAMIC_CLIENT_REGISTRATION']);
            } else {
                $_ENV['OAUTH2_DYNAMIC_CLIENT_REGISTRATION'] = $previousEnv;
            }

            if ($previousServer === null) {
                unset($_SERVER['OAUTH2_DYNAMIC_CLIENT_REGISTRATION']);
            } else {
                $_SERVER['OAUTH2_DYNAMIC_CLIENT_REGISTRATION'] = $previousServer;
            }
        }
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private static function register(KernelBrowser $browser, array $metadata): array
    {
        $browser->request(
            'POST',
            self::REGISTRATION_PATH,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($metadata, JSON_THROW_ON_ERROR),
        );

        return self::decode($browser);
    }

    private static function registeredClientId(KernelBrowser $browser): string
    {
        $registration = self::register($browser, [
            'client_name' => 'Claude',
            'redirect_uris' => [self::CONNECTOR_REDIRECT_URI],
        ]);

        self::assertSame(
            Response::HTTP_CREATED,
            $browser->getResponse()->getStatusCode(),
            (string) $browser->getResponse()->getContent(),
        );

        $clientId = $registration['client_id'] ?? null;
        self::assertIsString($clientId);

        return $clientId;
    }

    private static function storedClient(KernelBrowser $browser, string $clientId): ClientInterface
    {
        $stored = $browser->getContainer()->get(ClientManagerInterface::class)->find($clientId);

        self::assertNotNull($stored, sprintf('Client "%s" was not persisted.', $clientId));

        return $stored;
    }

    /**
     * `/api/authorize` for a self-registered client, returning the code it
     * redirects with. PKCE parameters come from {@see TestingOAuthClient} so
     * the verifier used at the token endpoint cannot drift from the challenge
     * sent here.
     *
     * @param list<string> $scopes
     */
    private static function authorizationCode(KernelBrowser $browser, string $clientId, array $scopes): string
    {
        $browser->request('GET', '/api/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::CONNECTOR_REDIRECT_URI,
            'scope' => implode(' ', $scopes),
            'state' => 'opaque-state',
            'code_challenge' => TestingOAuthClient::codeChallenge(),
            'code_challenge_method' => 'S256',
        ]));

        // A self-registered client is by definition one the user has never seen
        // before, so this is exactly the case S8-T5's consent screen exists
        // for: the authorization is parked until the user approves it. That
        // interstitial IS the acceptance criterion for turning dynamic
        // registration on at all — without it, registering a client and sending
        // its authorization URL to a logged-in user is an account takeover.
        TestingOAuthClient::approveConsent($browser);

        $location = (string) $browser->getResponse()->headers->get('Location');

        self::assertStringStartsWith(
            self::CONNECTOR_REDIRECT_URI . '?',
            $location,
            'The authorization endpoint did not redirect to the registered URI: '
            . ($location !== '' ? $location : (string) $browser->getResponse()->getContent()),
        );

        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

        $code = $params['code'] ?? null;
        self::assertIsString($code, 'No authorization code: ' . $location);

        return $code;
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

    /**
     * Referenced so a reader (and static analysis) can follow the constants
     * this suite asserts against back to the class that owns them.
     */
    public function testTheScopeCeilingIsDerivedFromTheScopeEnum(): void
    {
        self::assertSame(McpScope::values(), DynamicClientRegistrar::scopeCeiling());
        self::assertNotContains('api', DynamicClientRegistrar::scopeCeiling());
    }
}
