<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpTokenAuthenticator;

/**
 * The RFC 9728 protected-resource metadata document (S1-T4).
 *
 * This is the ONE MCP-adjacent URL that must answer to an anonymous client —
 * it is what the 401 challenge points at, so a client reads it precisely when
 * it has no credentials. An access_control regression that pulled it under the
 * `^/` catch-all would turn every first contact into a redirect to `/login`
 * and would be invisible without the anonymous assertion below.
 */
final class ProtectedResourceMetadataTest extends WebTestCase
{
    public function testItIsServedWithoutAnyCredentials(): void
    {
        $client = self::createClient();

        $client->request('GET', McpTokenAuthenticator::RESOURCE_METADATA_PATH);

        $response = $client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));

        // Not a 302 to /login, and not the mcp firewall's 401 either.
        self::assertFalse($response->isRedirection());
        self::assertNull($response->headers->get('WWW-Authenticate'));
    }

    /**
     * The document is fetched at the exact path the challenge advertises — the
     * route and {@see McpTokenAuthenticator::RESOURCE_METADATA_PATH} are the
     * same constant, and this proves the constant really resolves to a route.
     */
    public function testResourceIsTheAbsoluteMcpEndpointUrl(): void
    {
        $document = self::fetch(self::createClient());

        self::assertSame('http://localhost/_mcp', $document['resource'] ?? null);
        self::assertSame(['http://localhost'], $document['authorization_servers'] ?? null);
    }

    /**
     * Both absolute URLs are derived from the live request, exactly like the
     * challenge URL in {@see AuthTest::testChallengeUrlFollowsTheRequestHost()}:
     * a hard-coded production host would be wrong on localhost, and a
     * hard-coded localhost would be wrong in production. RFC 9728 §3.3 makes
     * `resource` a matching requirement, so getting this wrong makes a
     * spec-compliant client reject the token it was handed.
     */
    public function testAbsoluteUrlsFollowTheRequestHost(): void
    {
        $client = self::createClient();

        $client->request(
            'GET',
            McpTokenAuthenticator::RESOURCE_METADATA_PATH,
            server: ['HTTP_HOST' => 'wboost.cz', 'HTTPS' => true],
        );

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $document = self::decode($client);

        self::assertSame('https://wboost.cz/_mcp', $document['resource'] ?? null);
        self::assertSame(['https://wboost.cz'], $document['authorization_servers'] ?? null);
    }

    /**
     * Derived from the enum, never from a literal list: adding an
     * {@see McpScope} case must not be able to leave this endpoint advertising
     * a stale set — which is exactly what a literal expectation here would
     * allow (it would keep passing while the document went out of date).
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
     * The authenticator reads `Authorization: Bearer …` and nothing else, so
     * advertising RFC 6750's form-body or query-parameter methods would be a
     * lie a client could act on.
     */
    public function testOnlyTheAuthorizationHeaderMethodIsAdvertised(): void
    {
        $document = self::fetch(self::createClient());

        self::assertSame(['header'], $document['bearer_methods_supported'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fetch(KernelBrowser $client): array
    {
        $client->request('GET', McpTokenAuthenticator::RESOURCE_METADATA_PATH);

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
