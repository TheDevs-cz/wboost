<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpTokenGenerator;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * The token CLI (S1-T5) as an operator actually uses it: create → list →
 * revoke.
 *
 * The load-bearing assertion is the ROUND TRIP — the plaintext the create
 * command prints is fed straight back into `/_mcp` as a bearer token. That is
 * the only thing proving the CLI's hashing and the authenticator's hashing
 * agree, and it is exactly the kind of mismatch that unit tests on either side
 * would both pass while every issued token 401s in production.
 */
final class TokenCommandsTest extends WebTestCase
{
    public function testCreatedTokenAuthenticatesUntilItIsRevoked(): void
    {
        $client = self::createClient();

        $created = self::execCommand('app:mcp:token:create', [
            'user-email' => TestDataFixture::USER_1_EMAIL,
            '--name' => 'CLI round trip',
            '--scopes' => 'templates:read,templates:export',
        ]);
        $created->assertCommandIsSuccessful();

        $token = self::extractToken($created);
        $tokenId = self::extractTokenId($created);

        // The secret is shown once, and the command has to say so. (Asserted on
        // a short fragment: the warning block is wrapped to the terminal width,
        // so a longer needle would break on a line boundary.)
        self::assertStringContainsString('Store the token now', $created->getDisplay());

        // 1. list shows it — id, owner, label, chosen scopes, status.
        $listed = self::execCommand('app:mcp:token:list');
        $listed->assertCommandIsSuccessful();

        $listing = $listed->getDisplay();
        self::assertStringContainsString($tokenId, $listing);
        self::assertStringContainsString('CLI round trip', $listing);
        self::assertStringContainsString(TestDataFixture::USER_1_EMAIL, $listing);
        self::assertStringContainsString('templates:read, templates:export', $listing);
        self::assertStringContainsString('active', $listing);
        // Nothing secret leaks into the listing — neither the token nor its hash.
        self::assertStringNotContainsString($token, $listing);
        self::assertStringNotContainsString((new McpTokenGenerator())->hash($token), $listing);

        // 2. it opens the door, as the right user and carrying the chosen
        //    scopes (stored raw — templates:read is reported because
        //    templates:export IMPLIES it, not because it was expanded on write).
        // connect() throws unless `initialize` came back with a session id, so
        // reaching the tool call at all already proves the token authenticated.
        $sessionId = TestingMcpClient::connect($client, $token);

        TestingMcpClient::request($client, 'tools/call', ['name' => 'auth_probe', 'arguments' => []], $sessionId, $token);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            sprintf(
                '%s|%s,%s',
                TestDataFixture::USER_1_EMAIL,
                McpScope::TemplatesExport->value,
                McpScope::TemplatesRead->value,
            ),
            (string) $client->getResponse()->getContent(),
        );

        // 3. revoke
        $revoked = self::execCommand('app:mcp:token:revoke', ['token-id' => $tokenId]);
        $revoked->assertCommandIsSuccessful();
        self::assertStringContainsString('revoked', $revoked->getDisplay());

        // 4. the same token is dead on the very next call — the firewall is
        //    stateless, so there is no session left holding the door open.
        TestingMcpClient::initialize($client, $token);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'error="invalid_token"',
            (string) $client->getResponse()->headers->get('WWW-Authenticate'),
        );

        // …and the listing says so rather than dropping the row: the list
        // doubles as the record of what was ever handed out.
        self::assertMatchesRegularExpression(
            sprintf('/%s.*revoked/', preg_quote($tokenId, '/')),
            self::execCommand('app:mcp:token:list')->getDisplay(),
        );
    }

    /**
     * Revoking twice is a no-op, not an error: an operator who is not sure
     * whether the first attempt landed must be able to just run it again.
     */
    public function testRevokingAnAlreadyRevokedTokenSucceeds(): void
    {
        self::createClient();

        $result = self::execCommand('app:mcp:token:revoke', ['token-id' => TestDataFixture::MCP_TOKEN_REVOKED_ID]);

        $result->assertCommandIsSuccessful();
        self::assertStringContainsString('already revoked', $result->getDisplay());
    }

    public function testRevokingAnUnknownTokenFails(): void
    {
        self::createClient();

        $result = self::execCommand('app:mcp:token:revoke', ['token-id' => '00000000-0000-0000-0000-00000000dead']);

        self::assertSame(Command::FAILURE, $result->getStatusCode());
        self::assertStringContainsString('was not found', $result->getDisplay());
    }

    /**
     * A mistyped scope must be refused loudly. `McpScope::fromStrings()`
     * tolerating unknown values is about STORED rows surviving a release; the
     * CLI is a human input surface, where silently issuing a token with fewer
     * powers than asked for is the worst possible outcome.
     */
    public function testUnknownScopeIsRefused(): void
    {
        self::createClient();

        $result = self::execCommand('app:mcp:token:create', [
            'user-email' => TestDataFixture::USER_1_EMAIL,
            '--scopes' => 'templates:read,templates:reed',
        ]);

        self::assertSame(Command::FAILURE, $result->getStatusCode());

        $display = $result->getDisplay();
        self::assertStringContainsString('Unknown scope "templates:reed"', $display);
        self::assertStringContainsString(McpScope::TemplatesDesign->value, $display, 'The error must list the valid scopes.');
        self::assertStringNotContainsString(McpTokenGenerator::TOKEN_PREFIX, $display, 'No token may be minted for a rejected input.');
    }

    public function testUnknownUserIsRefused(): void
    {
        self::createClient();

        $result = self::execCommand('app:mcp:token:create', ['user-email' => 'nobody@test.cz']);

        self::assertSame(Command::FAILURE, $result->getStatusCode());
        self::assertStringContainsString('was not found', $result->getDisplay());
    }

    /**
     * Omitting --scopes must not hand out a powerful token: the default is the
     * read-only scope, so a token created in a hurry cannot author designs.
     */
    public function testDefaultScopeIsReadOnly(): void
    {
        $client = self::createClient();

        $created = self::execCommand('app:mcp:token:create', [
            'user-email' => TestDataFixture::USER_1_EMAIL,
            '--name' => 'Default scopes',
        ]);
        $created->assertCommandIsSuccessful();

        $token = self::extractToken($created);

        $sessionId = TestingMcpClient::connect($client, $token);
        TestingMcpClient::request($client, 'tools/call', ['name' => 'auth_probe', 'arguments' => []], $sessionId, $token);

        self::assertStringContainsString(
            TestDataFixture::USER_1_EMAIL . '|' . McpScope::TemplatesRead->value,
            (string) $client->getResponse()->getContent(),
        );
    }

    /**
     * The legacy `CommandTester::execute()` on purpose: Symfony 8's newer
     * result-based `runCommand()` renders into a `TestOutput`, which refuses
     * `section()` — and `SymfonyStyle::table()`/`definitionList()` ask for a
     * section whenever the output is a console output. Every one of these
     * commands prints a table, so the new API cannot run them at all.
     *
     * @param array<string, string> $input
     */
    private static function execCommand(string $name, array $input = []): CommandTester
    {
        // Built per call: the browser reboots the kernel between requests, so a
        // long-lived Application would hand out commands from a dead container.
        $application = new Application(static::getContainer()->get('kernel'));
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find($name));
        $tester->execute($input);

        return $tester;
    }

    private static function extractToken(CommandTester $result): string
    {
        self::assertSame(
            1,
            preg_match(
                '/' . preg_quote(McpTokenGenerator::TOKEN_PREFIX, '/') . '[A-Za-z0-9_-]+/',
                $result->getDisplay(),
                $matches,
            ),
            'The create command printed no access token.',
        );

        return $matches[0];
    }

    private static function extractTokenId(CommandTester $result): string
    {
        self::assertSame(
            1,
            preg_match(
                '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
                $result->getDisplay(),
                $matches,
            ),
            'The create command printed no token id.',
        );

        return $matches[0];
    }
}
