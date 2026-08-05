<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\McpAccessToken;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpScopeSelection;
use WBoost\Web\Mcp\Security\McpTokenGenerator;
use WBoost\Web\Message\Mcp\CreateMcpAccessToken;
use WBoost\Web\Message\Mcp\RevokeMcpAccessToken;
use WBoost\Web\Repository\McpAccessTokenRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\McpAccessTokenStatus;

/**
 * The token lifecycle end to end: issue → authenticate → revoke → 401.
 *
 * **The one thing only this test can prove** is that the WRITE path's hashing
 * and the AUTHENTICATOR's hashing agree. Both sides pass their own unit tests
 * while every issued token 401s in production if they drift, and the only way
 * to catch that is to feed a freshly minted plaintext straight back into
 * `/_mcp` over real HTTP.
 *
 * It is driven through the MESSAGE BUS rather than through
 * `app:mcp:token:create` on purpose: the commands are thin wrappers around this
 * exact call, so testing them would mean regex-scraping a rendered terminal
 * table to get at logic that is directly reachable — and would tie the
 * assertions to the wording of the output.
 */
final class AccessTokenLifecycleTest extends WebTestCase
{
    public function testAnIssuedTokenAuthenticatesUntilItIsRevoked(): void
    {
        $browser = self::createClient();

        $tokenId = Uuid::uuid7();
        $plainTextToken = (new McpTokenGenerator())->generate();

        self::dispatch(new CreateMcpAccessToken(
            $tokenId,
            Uuid::fromString(TestDataFixture::USER_1_ID),
            'Lifecycle round trip',
            McpScopeSelection::parse('templates:read,templates:export'),
            $plainTextToken,
        ));

        // The secret never reaches the row — only its sha256 does, so a dump
        // carries nothing replayable.
        $token = self::token($tokenId);
        self::assertSame((new McpTokenGenerator())->hash($plainTextToken), $token->tokenHash);
        self::assertStringNotContainsString($plainTextToken, $token->tokenHash);
        self::assertSame(McpAccessTokenStatus::Active, $token->status(new DateTimeImmutable()));

        // connect() throws unless `initialize` came back with a session id, so
        // reaching the tool call at all already proves the token authenticated.
        $sessionId = TestingMcpClient::connect($browser, $plainTextToken);

        TestingMcpClient::request(
            $browser,
            'tools/call',
            ['name' => 'auth_probe', 'arguments' => []],
            $sessionId,
            $plainTextToken,
        );

        self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode());

        // It acts as the right user and carries exactly the chosen scopes —
        // templates:read is reported because templates:export IMPLIES it at
        // check time, not because the write path expanded it.
        self::assertStringContainsString(
            sprintf(
                '%s|%s,%s',
                TestDataFixture::USER_1_EMAIL,
                McpScope::TemplatesExport->value,
                McpScope::TemplatesRead->value,
            ),
            (string) $browser->getResponse()->getContent(),
        );

        self::dispatch(new RevokeMcpAccessToken($tokenId));

        // Dead on the very next call: the `mcp` firewall is stateless, so there
        // is no session left holding the door open.
        TestingMcpClient::initialize($browser, $plainTextToken);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'error="invalid_token"',
            (string) $browser->getResponse()->headers->get('WWW-Authenticate'),
        );

        // …and the row survives as the record of what was ever handed out —
        // which is what `app:mcp:token:list` renders.
        $token = self::token($tokenId);
        self::assertTrue($token->isRevoked());
        self::assertSame(McpAccessTokenStatus::Revoked, $token->status(new DateTimeImmutable()));
    }

    /**
     * Omitting `--scopes` must not hand out a powerful token. The default is
     * parsed here rather than asserted as a literal, so this follows the
     * constant the console option uses.
     */
    public function testTheDefaultSelectionIssuesAReadOnlyToken(): void
    {
        $browser = self::createClient();

        $plainTextToken = (new McpTokenGenerator())->generate();

        self::dispatch(new CreateMcpAccessToken(
            Uuid::uuid7(),
            Uuid::fromString(TestDataFixture::USER_1_ID),
            'Default scopes',
            McpScopeSelection::parse(McpScopeSelection::DEFAULT_SCOPES),
            $plainTextToken,
        ));

        $sessionId = TestingMcpClient::connect($browser, $plainTextToken);
        TestingMcpClient::request(
            $browser,
            'tools/call',
            ['name' => 'auth_probe', 'arguments' => []],
            $sessionId,
            $plainTextToken,
        );

        self::assertStringContainsString(
            TestDataFixture::USER_1_EMAIL . '|' . McpScope::TemplatesRead->value,
            (string) $browser->getResponse()->getContent(),
        );
    }

    /**
     * Revoking twice is a no-op, not an error: an operator who is not sure
     * whether the first attempt landed must be able to just run it again, and
     * the original timestamp is the one that belongs in the audit trail.
     */
    public function testRevokingAnAlreadyRevokedTokenKeepsTheOriginalTimestamp(): void
    {
        self::bootKernel();

        $tokenId = Uuid::fromString(TestDataFixture::MCP_TOKEN_REVOKED_ID);
        $revokedAt = self::token($tokenId)->revokedAt;

        self::assertNotNull($revokedAt, 'The fixture token is expected to be revoked already.');

        self::dispatch(new RevokeMcpAccessToken($tokenId));

        self::assertEquals($revokedAt, self::token($tokenId)->revokedAt);
    }

    private static function dispatch(object $message): void
    {
        // Resolved per call: the browser reboots the kernel between requests, so
        // a bus held across one would belong to a dead container.
        self::getContainer()->get(MessageBusInterface::class)->dispatch($message);
    }

    private static function token(UuidInterface $tokenId): McpAccessToken
    {
        return self::getContainer()->get(McpAccessTokenRepository::class)->get($tokenId);
    }
}
