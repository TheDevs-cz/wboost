<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp;

use Mcp\Exception\ToolNotFoundException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpScopeChecker;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * Tool gating and `tools/list` filtering (S1-T6) — the two INDEPENDENT halves
 * of "a token only reaches what its scopes cover".
 *
 * **Filtering** is about not advertising: a `templates:read` token must not
 * learn the design tools exist. **Gating** is about not executing: a client can
 * call a tool it was never shown, so the refusal must not depend on the listing
 * having been filtered. {@see testFilteredOutToolIsStillRefusedWhenCalledByName()}
 * is the one that proves the second is not merely a consequence of the first.
 *
 * The tools involved are the `test`-env fixtures under `tests/Mcp/Fixtures/`
 * (registered by `config/services_test.php`): one per scope tier, plus one that
 * declares no scope at all.
 */
final class ScopeFilteringTest extends WebTestCase
{
    /**
     * Everything a `templates:read` token may reach. Asserted as an EXACT set —
     * "the design tool is absent" would also pass against a filter that hid
     * every tool, which is a broken server, not a secure one.
     *
     * Sorted; the `*_probe` entries are the `test`-env fixtures, the rest are
     * production tools. **Every new `templates:read` tool has to be added
     * here** — that this list needs editing is the point: a tool appearing in a
     * token's listing is a deliberate act, not a side effect.
     */
    private const array READ_TOOLS = ['auth_probe', 'get_context', 'scope_read_probe', 'transport_probe'];

    private const string DESIGN_TOOL = 'scope_design_probe';

    private const string UNSCOPED_TOOL = 'unscoped_probe';

    public function testReadOnlyTokenSeesExactlyTheReadTools(): void
    {
        self::assertSame(
            self::READ_TOOLS,
            self::listTools(self::createClient(), TestDataFixture::MCP_TOKEN_READ_ONLY),
        );
    }

    /**
     * The 403 names the scope that was MISSING, not the ones the token has, so
     * an agent can tell its user exactly what to add to the token.
     */
    public function testCallingADesignToolWithAReadTokenIsForbidden(): void
    {
        $client = self::createClient();

        $response = self::callTool($client, TestDataFixture::MCP_TOKEN_READ_ONLY, self::DESIGN_TOOL);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $challenge = (string) $response->headers->get('WWW-Authenticate');
        self::assertStringStartsWith('Bearer ', $challenge);
        self::assertStringContainsString('error="insufficient_scope"', $challenge);
        self::assertStringContainsString('scope="templates:design"', $challenge);

        // The body has to be intelligible too: the refusal happens at the HTTP
        // layer, so there is no JSON-RPC error object to read.
        $body = self::decode($response);
        self::assertSame('insufficient_scope', $body['error'] ?? null);
        self::assertSame('templates:design', $body['scope'] ?? null);
    }

    /**
     * THE point of having a gate at all. The design tool is not in the read
     * token's listing — and calling it by name anyway, exactly as a client that
     * cached an older listing (or simply guessed) would, is still refused.
     * A filter is not an authorisation boundary.
     */
    public function testFilteredOutToolIsStillRefusedWhenCalledByName(): void
    {
        $client = self::createClient();

        self::assertNotContains(
            self::DESIGN_TOOL,
            self::listTools($client, TestDataFixture::MCP_TOKEN_READ_ONLY),
        );

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            self::callTool($client, TestDataFixture::MCP_TOKEN_READ_ONLY, self::DESIGN_TOOL)->getStatusCode(),
        );
    }

    public function testDesignTokenSeesAndCallsTheDesignTool(): void
    {
        $client = self::createClient();

        self::assertContains(
            self::DESIGN_TOOL,
            self::listTools($client, TestDataFixture::MCP_TOKEN_DESIGN_ONLY),
        );

        $response = self::callTool($client, TestDataFixture::MCP_TOKEN_DESIGN_ONLY, self::DESIGN_TOOL);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringNotContainsString('"error"', (string) $response->getContent());
        self::assertStringContainsString('design-ok', (string) $response->getContent());
    }

    /**
     * `templates:design` implies `templates:read`, and the implication has to
     * hold end to end — in the listing AND at the gate. The fixture token
     * carries `templates:design` and nothing else, so a read tool it can reach
     * proves the closure is applied, not that the token was over-provisioned.
     */
    public function testDesignTokenInheritsTheReadToolsThroughImplication(): void
    {
        $client = self::createClient();

        self::assertSame(
            self::READ_TOOLS,
            array_values(array_intersect(
                self::listTools($client, TestDataFixture::MCP_TOKEN_DESIGN_ONLY),
                self::READ_TOOLS,
            )),
        );

        $response = self::callTool($client, TestDataFixture::MCP_TOKEN_DESIGN_ONLY, 'scope_read_probe');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('read-ok', (string) $response->getContent());
    }

    /**
     * A tool whose class forgot `#[McpToolScope]` is denied to EVERYONE — the
     * token used here holds every scope there is. There is no default scope to
     * fall back to, so forgetting the attribute can only ever take a tool away,
     * never hand one out.
     */
    public function testToolWithoutADeclaredScopeIsInvisibleAndDenied(): void
    {
        $client = self::createClient();

        self::assertNotContains(
            self::UNSCOPED_TOOL,
            self::listTools($client, TestDataFixture::MCP_TOKEN_ACTIVE),
        );

        $response = self::callTool($client, TestDataFixture::MCP_TOKEN_ACTIVE, self::UNSCOPED_TOOL);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        // No scope is advertised: none would have helped.
        self::assertStringNotContainsString('scope=', (string) $response->headers->get('WWW-Authenticate'));
    }

    /**
     * A name nobody registered is NOT the gate's business: it must reach the
     * SDK and come back as an ordinary JSON-RPC "method not found", so a client
     * that hallucinated a tool name is told the truth instead of being sent
     * hunting for a permission that does not exist.
     */
    public function testUnknownToolNameStillGetsTheProtocolError(): void
    {
        $client = self::createClient();

        $response = self::callTool($client, TestDataFixture::MCP_TOKEN_ACTIVE, 'no_such_tool');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('"error"', (string) $response->getContent());
    }

    /**
     * The refusal is TWO layers, and this is the lower one: even reaching the
     * SDK's registry directly — as a transport that never passes through
     * {@see \WBoost\Web\Mcp\Security\ScopeGuardedMcpController} would — a
     * forbidden tool cannot be resolved into something callable.
     *
     * Driven through the container rather than over HTTP precisely because the
     * HTTP guard answers first and would otherwise mask this layer entirely.
     */
    public function testRegistryRefusesAForbiddenToolEvenWithoutTheHttpGate(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // Building the server is what runs the loaders that populate the registry.
        $container->get('mcp.server');

        $token = new PostAuthenticationToken(new InMemoryUser('probe', null), 'mcp', ['ROLE_USER']);
        $token->setAttribute(McpScopeChecker::TOKEN_ATTRIBUTE, [McpScope::TemplatesRead->value]);

        $container->get('security.token_storage')->setToken($token);

        $registry = $container->get('mcp.registry');

        // The read tool resolves…
        self::assertSame('scope_read_probe', $registry->getTool('scope_read_probe')->tool->name);

        // …the design tool does not, even though it IS registered.
        self::assertTrue($registry->hasTool(self::DESIGN_TOOL), 'The design tool is not registered at all.');

        $this->expectException(ToolNotFoundException::class);
        $registry->getTool(self::DESIGN_TOOL);
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

        $payload = self::decode($response);
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

        TestingMcpClient::request(
            $client,
            'tools/call',
            ['name' => $tool, 'arguments' => []],
            $sessionId,
            $token,
        );

        return $client->getResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
