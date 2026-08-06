<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\OAuth2;

use League\Bundle\OAuth2ServerBundle\EventListener\AddClientDefaultScopesListener;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\InMemory\ClientManager;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use WBoost\Web\Exceptions\ClientRegistrationFailed;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Services\OAuth2\DynamicClientRegistrar;

/**
 * The registration ceiling ({@see DynamicClientRegistrar}), exercised without a
 * container.
 *
 * {@see ClientRegistrationTest} drives the same class through real HTTP and is
 * where the acceptance test lives; this suite is for the decisions that are
 * invisible in a 201 — what a client is registered WITH when it asked for
 * something else, and which requests are refused outright.
 *
 * The manager is the bundle's own in-memory one, so `save()` dispatches the
 * same `PreSaveClientEvent` the Doctrine one does. That matters for one test in
 * particular: it is that event the default-scope listener rides on.
 */
final class DynamicClientRegistrarTest extends TestCase
{
    private const string REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';

    /**
     * Both grants, ALWAYS, whatever was asked for — and the second one is the
     * trap.
     *
     * League issues a refresh token with every authorization-code exchange
     * regardless of what the client registered, but
     * `ClientRepository::validateClient()` refuses to redeem one unless the
     * client names the grant. A client registered with `authorization_code`
     * alone therefore receives a credential its own registration forbids it to
     * use, and finds out an hour later.
     */
    public function testEveryClientIsRegisteredWithBothGrantsWhateverItAskedFor(): void
    {
        $client = $this->registrar()->register([
            'redirect_uris' => [self::REDIRECT_URI],
            'grant_types' => [OAuth2Grants::AUTHORIZATION_CODE],
        ]);

        self::assertEqualsCanonicalizing(
            [OAuth2Grants::AUTHORIZATION_CODE, OAuth2Grants::REFRESH_TOKEN],
            array_map(strval(...), $client->getGrants()),
        );
    }

    /**
     * The mirror-image trap. The bundle's `AddClientDefaultScopesListener` runs
     * on `PreSaveClientEvent` and stamps the configured DEFAULT scope onto any
     * client saved with none of its own — and the default is the legacy blanket
     * `api` scope of the REST API. `ScopeRepository::setupScopes` then refuses
     * anything outside that list, so the client sails through `/api/authorize`
     * and fails at the token exchange with `invalid_scope`.
     *
     * The in-memory manager dispatches that very event, so a registrar that
     * stopped calling `setScopes()` would fail here.
     */
    public function testAClientWithNoRequestedScopeIsGivenTheMcpScopesExplicitly(): void
    {
        $dispatcher = new EventDispatcher();

        // The bundle's real listener, wired the way the bundle wires it: it
        // stamps `league_oauth2_server.scopes.default` onto any client that
        // reaches `save()` with no scopes of its own. Here that default is the
        // blanket `api` scope, exactly as in config/packages/league_oauth2_server.php.
        $dispatcher->addListener(
            OAuth2Events::PRE_SAVE_CLIENT,
            new AddClientDefaultScopesListener(['api']),
        );

        $client = (new DynamicClientRegistrar(new ClientManager($dispatcher)))->register([
            'redirect_uris' => [self::REDIRECT_URI],
        ]);

        self::assertEqualsCanonicalizing(
            McpScope::values(),
            array_map(strval(...), $client->getScopes()),
            'The default-scope listener got hold of the client — it was saved without explicit scopes.',
        );
        self::assertNotContains('api', array_map(strval(...), $client->getScopes()));
    }

    public function testARequestedSubsetOfTheCeilingIsHonoured(): void
    {
        $client = $this->registrar()->register([
            'redirect_uris' => [self::REDIRECT_URI],
            'scope' => McpScope::TemplatesRead->value . '  ' . McpScope::TemplatesExport->value,
        ]);

        self::assertSame(
            [McpScope::TemplatesRead->value, McpScope::TemplatesExport->value],
            array_map(strval(...), $client->getScopes()),
        );
    }

    public function testDuplicateScopesAreCollapsed(): void
    {
        $client = $this->registrar()->register([
            'redirect_uris' => [self::REDIRECT_URI],
            'scope' => sprintf('%1$s %1$s', McpScope::TemplatesRead->value),
        ]);

        self::assertSame([McpScope::TemplatesRead->value], array_map(strval(...), $client->getScopes()));
    }

    /**
     * A registered client is PUBLIC — no secret — which is what makes PKCE
     * mandatory for it (`require_code_challenge_for_public_clients`). Minting a
     * secret for an anonymous caller would be a credential we then have to
     * protect, in exchange for weaker protection of the flow.
     */
    public function testARegisteredClientIsPublicAndActive(): void
    {
        $client = $this->registrar()->register(['redirect_uris' => [self::REDIRECT_URI]]);

        self::assertNull($client->getSecret());
        self::assertFalse($client->isConfidential());
        self::assertTrue($client->isActive());
        self::assertFalse($client->isPlainTextPkceAllowed());
    }

    /**
     * The name is rendered to a human on the consent screen (S8-T5), so control
     * characters are stripped: a `\r` or a run of newlines is how you push the
     * real name off a line and put your own text where the user is reading.
     */
    public function testTheClientNameIsFlattenedAndCapped(): void
    {
        $client = $this->registrar()->register([
            'redirect_uris' => [self::REDIRECT_URI],
            'client_name' => "Claude\r\n\r\n     wboost official\u{0007}",
        ]);

        self::assertSame('Claude wboost official', $client->getName());

        $long = $this->registrar()->register([
            'redirect_uris' => [self::REDIRECT_URI],
            'client_name' => str_repeat('n', 400),
        ]);

        // `oauth2_client.name` is VARCHAR(128); over that the INSERT fails.
        self::assertSame(128, mb_strlen($long->getName()));
    }

    public function testAnUnnamedClientStillGetsAName(): void
    {
        foreach ([[], ['client_name' => ''], ['client_name' => "  \n "]] as $metadata) {
            $client = $this->registrar()->register($metadata + ['redirect_uris' => [self::REDIRECT_URI]]);

            self::assertNotSame('', $client->getName());
        }
    }

    /**
     * Identifiers are unique per registration and fit the PRIMARY KEY column.
     */
    public function testEachRegistrationMintsItsOwnIdentifier(): void
    {
        $registrar = $this->registrar();

        $first = $registrar->register(['redirect_uris' => [self::REDIRECT_URI]]);
        $second = $registrar->register(['redirect_uris' => [self::REDIRECT_URI]]);

        self::assertNotSame($first->getIdentifier(), $second->getIdentifier());
        self::assertSame(32, strlen($first->getIdentifier()));
        self::assertSame(32, strlen($second->getIdentifier()));
    }

    /**
     * ⚠️ The SSRF contract, asserted at the class that would have to break it.
     *
     * RFC 7591 metadata carries URL-valued fields an unauthenticated caller
     * chooses. This registrar has no HTTP client and must never acquire one:
     * every address below names a service on the internal docker network or a
     * cloud metadata endpoint, and each is accepted-and-discarded rather than
     * dereferenced. RFC 7591 §2 permits ignoring metadata the server does not
     * support, and ignoring it is what keeps registration free of any outbound
     * request at all.
     *
     * If a later task adds Client ID Metadata Documents — where the client_id
     * IS a URL the server fetches — this is the assumption it invalidates, and
     * the SSRF guard belongs there.
     */
    public function testUrlValuedMetadataIsNeitherFetchedNorStored(): void
    {
        $client = $this->registrar()->register([
            'redirect_uris' => [self::REDIRECT_URI],
            'logo_uri' => 'http://gotenberg:3000/health',
            'client_uri' => 'http://169.254.169.254/latest/meta-data/',
            'jwks_uri' => 'http://127.0.0.1:6379/',
            'tos_uri' => 'file:///etc/passwd',
            'contacts' => ['ops@evil.example'],
            'software_id' => 'anything',
        ]);

        // The Client model has no field to put any of it in, which is exactly
        // why nothing can be dereferenced later either.
        self::assertSame([self::REDIRECT_URI], array_map(strval(...), $client->getRedirectUris()));
        self::assertStringNotContainsString('gotenberg', $client->getName());
        self::assertStringNotContainsString('169.254', $client->getName());
    }

    /**
     * A software statement asserts that the client's metadata is ATTESTED. We
     * cannot verify one — no trusted issuer list, no key — so returning 201
     * while quietly substituting our own values would tell the client the
     * attestation was honoured when it was not.
     */
    public function testASoftwareStatementIsRefusedRatherThanSilentlyIgnored(): void
    {
        $this->expectException(ClientRegistrationFailed::class);

        try {
            $this->registrar()->register([
                'redirect_uris' => [self::REDIRECT_URI],
                'software_statement' => 'eyJhbGciOiJub25lIn0.e30.',
            ]);
        } catch (ClientRegistrationFailed $failure) {
            self::assertSame(ClientRegistrationFailed::INVALID_SOFTWARE_STATEMENT, $failure->error);

            throw $failure;
        }
    }

    /**
     * The grant that issues a token with NO user in the loop, and the one the
     * in-production REST API runs on. A self-registered client must never
     * reach it.
     */
    public function testTheClientCredentialsGrantCannotBeRegistered(): void
    {
        $this->expectException(ClientRegistrationFailed::class);
        $this->expectExceptionMessageMatches('/client_credentials/');

        $this->registrar()->register([
            'redirect_uris' => [self::REDIRECT_URI],
            'grant_types' => [OAuth2Grants::CLIENT_CREDENTIALS],
        ]);
    }

    public function testTheBlanketApiScopeCannotBeRegistered(): void
    {
        $this->expectException(ClientRegistrationFailed::class);

        try {
            $this->registrar()->register([
                'redirect_uris' => [self::REDIRECT_URI],
                'scope' => 'api',
            ]);
        } catch (ClientRegistrationFailed $failure) {
            self::assertSame(ClientRegistrationFailed::INVALID_CLIENT_METADATA, $failure->error);

            throw $failure;
        }
    }

    /**
     * Present-but-wrong-shape is an error, not a silent fallback to the
     * default: a caller that mistyped a field must learn which one, rather
     * than receive a client it did not describe.
     */
    public function testAMistypedFieldIsRefusedRatherThanIgnored(): void
    {
        foreach ([
            ['redirect_uris' => 'https://claude.ai/cb'],
            ['redirect_uris' => ['ok' => 'https://claude.ai/cb']],
            ['redirect_uris' => [self::REDIRECT_URI], 'grant_types' => 'authorization_code'],
            ['redirect_uris' => [self::REDIRECT_URI], 'scope' => ['templates:read']],
            ['redirect_uris' => [self::REDIRECT_URI], 'client_name' => 42],
        ] as $metadata) {
            try {
                $this->registrar()->register($metadata);
                self::fail('Accepted malformed metadata: ' . json_encode($metadata));
            } catch (ClientRegistrationFailed $failure) {
                self::assertSame(ClientRegistrationFailed::INVALID_CLIENT_METADATA, $failure->error);
            }
        }
    }

    /**
     * The ceiling is DERIVED from the scope enum, so a new case is offered the
     * day it is declared — and the legacy `api` scope, which is registered with
     * the authorization server and would otherwise be askable, never is.
     */
    public function testTheScopeCeilingIsTheScopeEnum(): void
    {
        self::assertSame(McpScope::values(), DynamicClientRegistrar::scopeCeiling());
        self::assertNotContains('api', DynamicClientRegistrar::scopeCeiling());
    }

    private function registrar(): DynamicClientRegistrar
    {
        return new DynamicClientRegistrar($this->clientManager());
    }

    private function clientManager(): ClientManagerInterface
    {
        return new ClientManager(new EventDispatcher());
    }
}
