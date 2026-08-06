<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\OAuth2;

use League\Bundle\OAuth2ServerBundle\Manager\ScopeManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Controller\OAuth2\AuthorizationServerMetadataController;
use WBoost\Web\Mcp\Security\McpScope;

/**
 * The RFC 8414 authorization-server metadata document (S8-T2).
 *
 * Together with `tests/Mcp/ProtectedResourceMetadataTest.php` this covers the
 * whole discovery chain a claude.ai / ChatGPT connector walks before it can
 * show the user a login button. Every assertion here is about a value a
 * spec-compliant client ACTS on: a wrong `issuer` or a wrong endpoint host
 * makes it abandon the flow, and neither would produce an error anywhere in
 * this application.
 */
final class AuthorizationServerMetadataTest extends WebTestCase
{
    /**
     * The document exists precisely for clients that have no credentials, so
     * an access_control regression pulling it under the `^/` catch-all — a 302
     * to /login — would be invisible without this.
     */
    public function testItIsServedWithoutAnyCredentials(): void
    {
        $client = self::createClient();

        $client->request('GET', AuthorizationServerMetadataController::METADATA_PATH);

        $response = $client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        self::assertFalse($response->isRedirection());
        self::assertNull($response->headers->get('WWW-Authenticate'));
    }

    /**
     * RFC 8414 §3.3: `issuer` MUST be the URL this document was fetched from
     * with the well-known path removed, and the endpoints must be absolute.
     * All three are derived from the live request, so a non-localhost HTTPS
     * host is what proves nothing is hard-coded — the same check
     * `ProtectedResourceMetadataTest` makes for the RFC 9728 document.
     */
    public function testEveryUrlFollowsTheRequestHost(): void
    {
        $client = self::createClient();

        $client->request(
            'GET',
            AuthorizationServerMetadataController::METADATA_PATH,
            server: ['HTTP_HOST' => 'wboost.cz', 'HTTPS' => true],
        );

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $document = self::decode($client);

        self::assertSame('https://wboost.cz', $document['issuer'] ?? null);
        self::assertSame('https://wboost.cz/api/authorize', $document['authorization_endpoint'] ?? null);
        self::assertSame('https://wboost.cz/api/token', $document['token_endpoint'] ?? null);
    }

    public function testItIsCorrectOnLocalhostToo(): void
    {
        $document = self::fetch(self::createClient());

        self::assertSame('http://localhost', $document['issuer'] ?? null);
        self::assertSame('http://localhost/api/authorize', $document['authorization_endpoint'] ?? null);
        self::assertSame('http://localhost/api/token', $document['token_endpoint'] ?? null);
    }

    /**
     * PKCE is the whole point of offering the auth-code grant to public
     * clients, and `plain` is not on the table (the bundle gates it behind a
     * per-client flag nothing sets). Advertising it would offer a method every
     * client would then be refused on.
     */
    public function testOnlyS256IsAdvertisedAsACodeChallengeMethod(): void
    {
        $document = self::fetch(self::createClient());

        self::assertSame(['S256'], $document['code_challenge_methods_supported'] ?? null);
    }

    public function testOnlyTheCodeResponseTypeIsAdvertised(): void
    {
        $document = self::fetch(self::createClient());

        self::assertSame(['code'], $document['response_types_supported'] ?? null);
    }

    /**
     * Both grants this server really serves — the in-production
     * client_credentials flow of the REST API and the new interactive one —
     * and neither of the ones that are switched off.
     */
    public function testGrantTypesMirrorTheEnabledGrants(): void
    {
        $document = self::fetch(self::createClient());

        $grants = $document['grant_types_supported'] ?? null;
        self::assertIsArray($grants);

        self::assertContains('authorization_code', $grants);
        self::assertContains('client_credentials', $grants);
        self::assertNotContains('password', $grants);
        self::assertNotContains('implicit', $grants);
    }

    /**
     * Derived from the enum, never from a literal list: adding an
     * {@see McpScope} case must not be able to leave this endpoint advertising
     * a stale set, which a literal expectation here would happily allow.
     */
    public function testScopesSupportedMirrorsTheScopeEnum(): void
    {
        $document = self::fetch(self::createClient());

        $expected = array_map(static fn (McpScope $scope): string => $scope->value, McpScope::cases());

        $advertised = $document['scopes_supported'] ?? null;
        self::assertIsArray($advertised);

        self::assertEqualsCanonicalizing($expected, $advertised);
        self::assertCount(count($expected), $advertised, 'scopes_supported advertises a duplicate.');
    }

    /**
     * Every advertised scope must ALSO be registered with the bundle
     * (`league_oauth2_server.scopes.available`), or the authorization server
     * answers `invalid_scope` to a client that asked for exactly what this
     * document told it to ask for.
     *
     * The config derives the list from the enum, so this can only fail on a
     * container cached before the enum changed — the enum file is not a config
     * resource, so nothing else would notice.
     */
    public function testEveryAdvertisedScopeIsRegisteredWithTheBundle(): void
    {
        self::createClient();

        $scopeManager = self::getContainer()->get(ScopeManagerInterface::class);

        foreach (McpScope::cases() as $scope) {
            self::assertNotNull(
                $scopeManager->find($scope->value),
                \sprintf('Scope "%s" is advertised but not registered with the authorization server.', $scope->value),
            );
        }

        // The legacy blanket scope of the client_credentials REST API must not
        // have been dropped while adding the MCP ones.
        self::assertNotNull($scopeManager->find('api'));
    }

    /**
     * S8-T4 has not landed, so claiming support would make a client attempt a
     * URL `client_id` this server cannot dereference instead of falling back
     * to a registered one.
     */
    public function testClientIdMetadataDocumentsAreNotClaimedYet(): void
    {
        $document = self::fetch(self::createClient());

        self::assertFalse($document['client_id_metadata_document_supported'] ?? null);
        self::assertArrayNotHasKey('registration_endpoint', $document);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fetch(KernelBrowser $client): array
    {
        $client->request('GET', AuthorizationServerMetadataController::METADATA_PATH);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        return self::decode($client);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(KernelBrowser $client): array
    {
        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }
}
