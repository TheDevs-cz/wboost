<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpTokenAuthenticator;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * The `mcp` firewall (S1-T3): who gets into `/_mcp`, and what the door says
 * when they do not.
 *
 * The failure assertions are deliberately about the CHALLENGE, not just the
 * status: before this firewall existed `/_mcp` sat under `main` and an
 * anonymous POST was answered with a 302 to `/login`, which an MCP client
 * cannot act on. 401 + `WWW-Authenticate: Bearer resource_metadata=…` is the
 * contract.
 */
final class AuthTest extends WebTestCase
{
    private const string EXPECTED_RESOURCE_METADATA = 'http://localhost/.well-known/oauth-protected-resource';

    public function testMissingAuthorizationHeaderIsChallenged(): void
    {
        $client = self::createClient();

        TestingMcpClient::initialize($client);

        $response = $client->getResponse();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $challenge = self::challenge($response);
        self::assertStringStartsWith('Bearer ', $challenge);
        self::assertStringContainsString(
            sprintf('resource_metadata="%s"', self::EXPECTED_RESOURCE_METADATA),
            $challenge,
        );
        self::assertStringContainsString('scope="templates:read"', $challenge);

        // Nothing was presented, so RFC 6750 §3.1 says no error code.
        self::assertStringNotContainsString('error=', $challenge);
    }

    /**
     * The absolute URL in the challenge is derived from the request, so it is
     * right on localhost AND on wboost.cz — a hard-coded host would be wrong on
     * one of them.
     */
    public function testChallengeUrlFollowsTheRequestHost(): void
    {
        $client = self::createClient();

        $client->request(
            'POST',
            '/_mcp',
            server: ['HTTP_HOST' => 'wboost.cz', 'HTTPS' => true] + TestingMcpClient::server(),
        );

        self::assertStringContainsString(
            sprintf('resource_metadata="https://wboost.cz%s"', McpTokenAuthenticator::RESOURCE_METADATA_PATH),
            self::challenge($client->getResponse()),
        );
    }

    /**
     * A bearer token belonging to some other scheme is not ours to validate:
     * it fails the prefix check and never costs a query, but the answer is
     * still the challenge rather than a pass-through.
     */
    public function testForeignBearerTokenIsChallenged(): void
    {
        $client = self::createClient();

        TestingMcpClient::initialize($client, 'not-an-mcp-token');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('resource_metadata=', self::challenge($client->getResponse()));
    }

    public function testUnknownTokenIsRejected(): void
    {
        $client = self::createClient();

        TestingMcpClient::initialize($client, 'wb_mcp_bogus');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());

        $challenge = self::challenge($client->getResponse());
        self::assertStringContainsString('error="invalid_token"', $challenge);
        self::assertStringContainsString(
            sprintf('resource_metadata="%s"', self::EXPECTED_RESOURCE_METADATA),
            $challenge,
        );
    }

    public function testRevokedTokenIsRejected(): void
    {
        $client = self::createClient();

        TestingMcpClient::initialize($client, TestDataFixture::MCP_TOKEN_REVOKED);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', self::challenge($client->getResponse()));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $client = self::createClient();

        TestingMcpClient::initialize($client, TestDataFixture::MCP_TOKEN_EXPIRED);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', self::challenge($client->getResponse()));
    }

    /**
     * The firewall carries the same `UserChecker` as `main`, so a token held by
     * a never-activated account is dead weight — otherwise an invitee who never
     * confirmed (or an account that was deactivated) would keep an MCP door
     * open that the web login has closed.
     */
    public function testTokenOfAnUnconfirmedUserIsRejected(): void
    {
        $client = self::createClient();

        TestingMcpClient::initialize($client, TestDataFixture::MCP_TOKEN_UNCONFIRMED);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    /**
     * The happy path, asserted on IDENTITY rather than on the status code: a
     * mis-wired authenticator that resolved the wrong user would satisfy a
     * 200-only assertion. The probe tool reads the security context from inside
     * the request, and reports the scopes through `McpScopeChecker` — which is
     * also the proof that the S1-T2 seam (`mcp_scopes` token attribute) is fed.
     */
    public function testValidTokenAuthenticatesItsOwnUserAndCarriesItsScopes(): void
    {
        $client = self::createClient();

        $sessionId = TestingMcpClient::connect($client, TestDataFixture::MCP_TOKEN_ACTIVE);

        TestingMcpClient::request(
            $client,
            'tools/call',
            ['name' => 'auth_probe', 'arguments' => []],
            $sessionId,
            TestDataFixture::MCP_TOKEN_ACTIVE,
        );

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $expectedScopes = array_map(static fn (McpScope $scope): string => $scope->value, McpScope::cases());
        sort($expectedScopes);

        $content = (string) $response->getContent();
        self::assertStringNotContainsString('"error"', $content);
        self::assertStringContainsString(
            TestDataFixture::USER_1_EMAIL . '|' . implode(',', $expectedScopes),
            $content,
        );
    }

    /**
     * `lastUsedAt` is written at most once a minute per token — an agent makes
     * many calls a minute and every one of them authenticates, so a write per
     * call would sit on the hot path.
     *
     * All three assertions read the COLUMN, not the entity: the authenticator
     * runs on a stateless firewall with no `flush()` anywhere near it, so the
     * only thing worth proving is that the row really moved.
     */
    public function testLastUsedAtIsWrittenOncePerMinute(): void
    {
        $client = self::createClient();
        $connection = self::connection();

        // 1. First use ever (last_used_at IS NULL) → written.
        self::authenticate($client);
        self::assertNotNull(self::lastUsedAt($connection), 'The first authenticated call did not record a use.');

        // 2. A second call moments later → throttled. The marker is a value the
        //    authenticator itself would never produce, so "unchanged" is proof
        //    of "no write", not an artefact of second-resolution timestamps.
        $insideWindow = new DateTimeImmutable('-10 seconds');
        self::setLastUsedAt($connection, $insideWindow);

        self::authenticate($client);
        self::assertSame(
            $insideWindow->format('Y-m-d H:i:s'),
            self::lastUsedAt($connection)?->format('Y-m-d H:i:s'),
            'A call inside the throttle window still wrote last_used_at.',
        );

        // 3. Once the window has passed → written again.
        self::setLastUsedAt($connection, new DateTimeImmutable('-5 minutes'));

        self::authenticate($client);
        $lastUsedAt = self::lastUsedAt($connection);
        self::assertNotNull($lastUsedAt);
        self::assertGreaterThan(
            new DateTimeImmutable('-1 minute'),
            $lastUsedAt,
            'A call after the throttle window did not refresh last_used_at.',
        );
    }

    private static function authenticate(KernelBrowser $client): void
    {
        TestingMcpClient::initialize($client, TestDataFixture::MCP_TOKEN_ACTIVE);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
    }

    private static function challenge(Response $response): string
    {
        $challenge = $response->headers->get('WWW-Authenticate');

        self::assertIsString($challenge, 'The 401 carried no WWW-Authenticate challenge.');

        return $challenge;
    }

    private static function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private static function setLastUsedAt(Connection $connection, DateTimeImmutable $at): void
    {
        $connection->executeStatement(
            'UPDATE mcp_access_token SET last_used_at = :at WHERE id = :id',
            ['at' => $at->format('Y-m-d H:i:s'), 'id' => TestDataFixture::MCP_TOKEN_ACTIVE_ID],
        );
    }

    private static function lastUsedAt(Connection $connection): null|DateTimeImmutable
    {
        $value = $connection->fetchOne(
            'SELECT last_used_at FROM mcp_access_token WHERE id = :id',
            ['id' => TestDataFixture::MCP_TOKEN_ACTIVE_ID],
        );

        if (!is_string($value)) {
            return null;
        }

        return new DateTimeImmutable($value);
    }
}
