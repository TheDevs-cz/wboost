<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\User;
use WBoost\Web\Mcp\Tool\GetContextTool;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Mcp\TestingMcpClient;

/**
 * `get_context` (S2-T1) — driven end to end over `/_mcp`, never as a bare
 * service call. Tool registration happens at COMPILE time (the SDK derives the
 * input schema from reflection), so a tool can be perfectly correct in
 * isolation and still fail to register; only a real `tools/call` proves it is
 * reachable.
 *
 * ## On the 60 s cache
 *
 * Nothing here asserts cache BEHAVIOUR, and that is deliberate rather than an
 * omission. `cache.app` is Redis and the suite has none (CI runs one `postgres`
 * service), so under test every read is a permanent miss — a "the second call
 * was cached" assertion would pass whether or not caching worked, which is
 * worse than no assertion at all. What is asserted instead is everything that
 * must hold either way: the computed result, that two calls agree, that two
 * users get their own answers, and — directly, without a backend — that the key
 * is per user.
 */
final class GetContextTest extends WebTestCase
{
    /**
     * One browser per test method, created on first use. `createClient()` may
     * only be called once per test (it boots the kernel), and several cases
     * here make two `get_context` calls — with two tokens, or twice with one.
     */
    private null|KernelBrowser $browser = null;

    public function testReturnsTheAuthenticatedTokenOwner(): void
    {
        $context = $this->getContext(TestDataFixture::MCP_TOKEN_ACTIVE);

        self::assertIsArray($context['user']);
        self::assertSame(TestDataFixture::USER_1_ID, $context['user']['id']);
        self::assertSame(TestDataFixture::USER_1_EMAIL, $context['user']['email']);
        self::assertSame('ROLE_USER', $context['user']['role']);
    }

    /**
     * The admin branch: `ROLE_ADMIN` in `user.role`, and — because the voter
     * grants an admin every project — a listing that includes one they neither
     * own nor were shared. That is the `GetProjects::all()` path; nothing else
     * exercises it.
     */
    public function testAdminSeesEveryProjectAndReportsTheAdminRole(): void
    {
        $context = $this->getContext(TestDataFixture::MCP_TOKEN_ADMIN);

        self::assertIsArray($context['user']);
        self::assertSame(TestDataFixture::ADMIN_USER_EMAIL, $context['user']['email']);
        self::assertSame(User::ROLE_ADMIN, $context['user']['role']);

        $projects = self::projectsById($context);
        self::assertArrayHasKey(TestDataFixture::PROJECT_1_ID, $projects);
        self::assertArrayHasKey(TestDataFixture::PROJECT_2_ID, $projects);
    }

    /**
     * The Done-when case: a user who owns nothing still gets the project that
     * was shared with them, through the ordinary `ProjectVoter::VIEW` rule.
     */
    public function testSharedUserSeesTheSharedProject(): void
    {
        $projects = self::projectsById($this->getContext(TestDataFixture::MCP_TOKEN_SHARED_USER));

        self::assertArrayHasKey(TestDataFixture::PROJECT_1_ID, $projects);
        self::assertSame('Project 1', $projects[TestDataFixture::PROJECT_1_ID]['name']);
    }

    /**
     * The other half of the same rule, and the one that matters: a project
     * neither owned by nor shared with the caller is absent — for the owner of
     * a different project AND for someone whose only project is a share.
     */
    public function testForeignProjectsAreAbsent(): void
    {
        self::assertArrayNotHasKey(
            TestDataFixture::PROJECT_2_ID,
            self::projectsById($this->getContext(TestDataFixture::MCP_TOKEN_ACTIVE)),
            'USER_1 must not see the project owned by USER_2.',
        );

        $shared = self::projectsById($this->getContext(TestDataFixture::MCP_TOKEN_SHARED_USER));

        self::assertArrayNotHasKey(TestDataFixture::PROJECT_2_ID, $shared);
        self::assertCount(1, $shared, 'The share recipient sees exactly the one shared project.');
    }

    /**
     * The font strings are a hard contract: a design document's `font` must
     * match one byte for byte, so this asserts them against the same query the
     * renderer and the export whitelist read, not against a literal.
     */
    public function testFontsMatchGetFonts(): void
    {
        $projects = self::projectsById($this->getContext(TestDataFixture::MCP_TOKEN_ACTIVE));

        /** @var list<string> $expected */
        $expected = [];

        foreach (self::getContainer()->get(GetFonts::class)->allForProject(Uuid::fromString(TestDataFixture::PROJECT_1_ID)) as $font) {
            foreach ($font->faces as $face) {
                $expected[] = $font->faceFamily($face);
            }
        }

        self::assertSame($expected, $projects[TestDataFixture::PROJECT_1_ID]['fonts']);

        // …and it is not vacuously an empty list on both sides: the fixture
        // project really does carry both Rubik faces, in face order.
        self::assertSame(['Rubik (Rubik Regular)', 'Rubik (Rubik Bold)'], $expected);
    }

    /**
     * The brand palette comes from the project's manual — primary first, then
     * secondary, normalized to lowercase `#rrggbb`.
     */
    public function testBrandColorsComeFromTheManual(): void
    {
        $projects = self::projectsById($this->getContext(TestDataFixture::MCP_TOKEN_ACTIVE));

        self::assertSame(['#c8102e', '#004e7c'], $projects[TestDataFixture::PROJECT_1_ID]['colors']);
    }

    /**
     * PROJECT_1 holds 4 templates / 6 variants across 3 distinct dimensions:
     * 1:1 three times, A4 twice, 9:16 once. Both faces of each size are
     * reported — the authored unit size and the canvas pixels (A4 rasterizes at
     * 300 DPI).
     */
    public function testTemplateCountsAndDistinctDimensions(): void
    {
        $project = self::projectsById($this->getContext(TestDataFixture::MCP_TOKEN_ACTIVE))[TestDataFixture::PROJECT_1_ID];

        self::assertSame(4, $project['templateCount']);
        self::assertSame(6, $project['variantCount']);

        $dimensions = $project['dimensions'];
        self::assertIsArray($dimensions);
        self::assertCount(3, $dimensions);

        $byLabel = [];
        foreach ($dimensions as $dimension) {
            self::assertIsArray($dimension);
            $label = $dimension['label'];
            self::assertIsString($label);
            $byLabel[$label] = $dimension;
        }

        // NOTE the whole `unit*` sizes arrive as JSON integers: PHP's
        // json_encode drops the zero fraction of a float, and the SDK controls
        // the encode flags. JSON has one number type, so this is a formatting
        // detail, not a contract change — but it is what a consumer sees.
        self::assertSame(
            ['label' => '1:1', 'preset' => '1:1', 'unit' => 'px', 'unitWidth' => 1080, 'unitHeight' => 1080, 'width' => 1080, 'height' => 1080, 'variantCount' => 3],
            $byLabel['1:1'],
        );
        self::assertSame(
            ['label' => '210 × 297 mm', 'preset' => null, 'unit' => 'mm', 'unitWidth' => 210, 'unitHeight' => 297, 'width' => 2480, 'height' => 3508, 'variantCount' => 2],
            $byLabel['210 × 297 mm'],
        );
        self::assertSame(
            ['label' => '9:16', 'preset' => '9:16', 'unit' => 'px', 'unitWidth' => 1080, 'unitHeight' => 1920, 'width' => 1080, 'height' => 1920, 'variantCount' => 1],
            $byLabel['9:16'],
        );
    }

    /**
     * Scopes are reported IMPLICATION-EXPANDED: the design-only token carries
     * `templates:design` and nothing else, so `templates:read` in the answer can
     * only have come from the closure.
     */
    public function testGrantedScopesAreImplicationExpanded(): void
    {
        $context = $this->getContext(TestDataFixture::MCP_TOKEN_DESIGN_ONLY);

        self::assertIsArray($context['scopes']);
        self::assertContains('templates:design', $context['scopes']);
        self::assertContains('templates:read', $context['scopes']);
        self::assertNotContains('templates:export', $context['scopes']);
    }

    public function testReadOnlyTokenReportsOnlyTheReadScope(): void
    {
        self::assertSame(['templates:read'], $this->getContext(TestDataFixture::MCP_TOKEN_READ_ONLY)['scopes']);
    }

    /**
     * Cold and warm must agree. This is NOT a cache-hit assertion (see the
     * class docblock) — it is the property that has to hold whichever way the
     * cache answers, and it is the one that would break if the cached payload
     * and the freshly computed one ever diverged.
     */
    public function testRepeatedCallsReturnTheSamePayload(): void
    {
        self::assertSame(
            $this->getContext(TestDataFixture::MCP_TOKEN_ACTIVE),
            $this->getContext(TestDataFixture::MCP_TOKEN_ACTIVE),
        );
    }

    /**
     * Two users, one process: whatever the cache does, one account's project
     * list must never be served to another. With a working backend this is also
     * what a shared (non-per-user) cache key would break.
     */
    public function testTwoUsersInOneProcessGetTheirOwnContext(): void
    {
        $owner = $this->getContext(TestDataFixture::MCP_TOKEN_ACTIVE);
        $recipient = $this->getContext(TestDataFixture::MCP_TOKEN_SHARED_USER);

        self::assertIsArray($owner['user']);
        self::assertIsArray($recipient['user']);
        self::assertSame(TestDataFixture::USER_1_EMAIL, $owner['user']['email']);
        self::assertSame(TestDataFixture::SHARED_USER_EMAIL, $recipient['user']['email']);
    }

    /**
     * The cache key asserted directly, because the suite has no Redis and an
     * over-HTTP assertion could therefore never fail on a shared key. A key
     * that is not per-user is a cross-account data leak, so it gets a test that
     * works with no backend at all.
     */
    public function testCacheKeyIsScopedToTheUser(): void
    {
        $one = Uuid::fromString(TestDataFixture::USER_1_ID);
        $two = Uuid::fromString(TestDataFixture::USER_2_ID);

        self::assertStringContainsString($one->toString(), GetContextTool::cacheKey($one));
        self::assertNotSame(GetContextTool::cacheKey($one), GetContextTool::cacheKey($two));
        self::assertSame(GetContextTool::cacheKey($one), GetContextTool::cacheKey($one));
    }

    /**
     * The description is the only thing that makes an agent reach for this tool
     * first and then USE the strings it returns verbatim, so the two
     * load-bearing sentences are locked here rather than left to drift.
     */
    public function testToolIsAdvertisedWithAnAgentFacingDescription(): void
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_READ_ONLY);

        TestingMcpClient::request($browser, 'tools/list', sessionId: $sessionId, token: TestDataFixture::MCP_TOKEN_READ_ONLY);

        $result = self::decode($browser->getResponse())['result'];
        self::assertIsArray($result);
        self::assertIsArray($result['tools']);

        $description = null;

        foreach ($result['tools'] as $tool) {
            self::assertIsArray($tool);

            if ($tool['name'] === 'get_context') {
                $description = $tool['description'];
            }
        }

        self::assertIsString($description);
        // Substrings must not span a line break — the description is the
        // reflowed `__invoke()` docblock, so a wrapped sentence would never
        // match.
        self::assertStringContainsString('Start here', $description);
        self::assertStringContainsString('a font MUST be one of', $description);
    }

    private function browser(): KernelBrowser
    {
        return $this->browser ??= self::createClient();
    }

    /**
     * Calls `get_context` and returns its decoded payload.
     *
     * @return array<string, mixed>
     */
    private function getContext(string $token): array
    {
        $browser = $this->browser();

        $sessionId = TestingMcpClient::connect($browser, $token);

        TestingMcpClient::request($browser, 'tools/call', [
            'name' => 'get_context',
            'arguments' => [],
        ], $sessionId, $token);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = self::decode($response);
        self::assertArrayNotHasKey('error', $payload, (string) $response->getContent());

        $result = $payload['result'];
        self::assertIsArray($result);
        self::assertIsArray($result['content']);
        self::assertIsArray($result['content'][0]);

        $text = $result['content'][0]['text'];
        self::assertIsString($text);

        $context = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($context);

        /** @var array<string, mixed> $context */
        return $context;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, array<string, mixed>>
     */
    private static function projectsById(array $context): array
    {
        $projects = $context['projects'];
        self::assertIsArray($projects);

        $byId = [];

        foreach ($projects as $project) {
            self::assertIsArray($project);
            $id = $project['id'];
            self::assertIsString($id);

            /** @var array<string, mixed> $project */
            $byId[$id] = $project;
        }

        return $byId;
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
