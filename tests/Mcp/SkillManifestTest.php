<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use WBoost\Web\DependencyInjection\McpToolScopePass;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpScopeChecker;
use WBoost\Web\Mcp\Skill\SkillRelease;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * Keeps the published wboost skill (`plugin/wboost/`) in step with the MCP
 * tools this build registers — the half of the drift guard that
 * {@see SkillDocumentationTest} (prose, scope table, DSL grammar) does not
 * cover. Process and reasoning: `docs/mcp/skill-maintenance.md`.
 *
 * | assertion | why |
 * |---|---|
 * | `references/tools.json` = every registered tool, its scope and its input parameters | a tool or parameter that ships without the skill learning of it fails here |
 * | every tool has a `## \`name(…)\`` section in `references/` and is named in `SKILL.md` | the manifest alone teaches nothing |
 * | one version everywhere (plugin.json, marketplace.json, SkillRelease, SKILL.md, tools.json, CHANGELOG) | a half-done bump cannot ship |
 * | SKILL.md frontmatter is what claude.ai accepts, body < 500 lines | an upload that fails is a skill nobody gets |
 * | server instructions ≤ 4096 characters and open with the skill line | Claude Code cuts the rest |
 *
 * The third leg — "the skill changed but the version did not" — needs git
 * history and lives in `.github/workflows/test.yml` (`skill-release-guard`).
 *
 * Regenerating the manifest after a deliberate tool change:
 *
 *     docker compose exec -e WBOOST_WRITE_SKILL_MANIFEST=1 web vendor/bin/phpunit tests/Mcp/SkillManifestTest.php
 */
final class SkillManifestTest extends WebTestCase
{
    private const string PLUGIN = __DIR__ . '/../../plugin/wboost';

    private const string SKILL_DIRECTORY = self::PLUGIN . '/skills/wboost';

    private const string SKILL = self::SKILL_DIRECTORY . '/SKILL.md';

    private const string MANIFEST = self::SKILL_DIRECTORY . '/references/tools.json';

    private const string PLUGIN_JSON = self::PLUGIN . '/.claude-plugin/plugin.json';

    private const string MARKETPLACE_JSON = __DIR__ . '/../../.claude-plugin/marketplace.json';

    private const string CHANGELOG = self::PLUGIN . '/CHANGELOG.md';

    private const string FIXTURE_TOOL_DIRECTORY = __DIR__ . '/Fixtures';

    private const string WRITE_ENV = 'WBOOST_WRITE_SKILL_MANIFEST';

    private const string REGENERATE = 'docker compose exec -e WBOOST_WRITE_SKILL_MANIFEST=1 web vendor/bin/phpunit tests/Mcp/SkillManifestTest.php';

    /** The frontmatter keys claude.ai (and the Skills API) accept — anything else fails the upload. */
    private const array UPLOADABLE_FRONTMATTER_KEYS = ['name', 'description', 'license', 'compatibility', 'metadata', 'allowed-tools'];

    private const int MAX_DESCRIPTION_LENGTH = 1024;

    private const int MAX_SKILL_BODY_LINES = 500;

    /** Claude Code shows only this much of the server `instructions`. */
    private const int MAX_INSTRUCTIONS_LENGTH = 4096;

    private const string HOW_TO_FIX = <<<'FIX'

        The wboost skill (plugin/wboost/) must describe every registered MCP tool. To fix:
          1. Document the tool / parameter: a "## `tool_name(args…)`" section in
             plugin/wboost/skills/wboost/references/*.md, the tool named in SKILL.md
             (with when to use it), and the scope table in references/tools.md.
          2. Regenerate the manifest:
             %s
          3. Bump the skill version (x.y.z) in ONE go: plugin/wboost/.claude-plugin/plugin.json,
             .claude-plugin/marketplace.json, src/Mcp/Skill/SkillRelease.php (VERSION + WHATS_NEW),
             SKILL.md (metadata.version + the skillVersion line), and a new top entry in
             plugin/wboost/CHANGELOG.md — then rerun this test.
        Full routine: docs/mcp/skill-maintenance.md (or the /skill-release command).
        FIX;

    public function testTheManifestMatchesTheRegisteredTools(): void
    {
        $registered = self::registeredManifestTools();

        if (getenv(self::WRITE_ENV) === '1') {
            $manifest = [
                'skillVersion' => SkillRelease::VERSION,
                'generatedBy' => self::REGENERATE,
                'note' => 'Generated from the MCP tools this build registers. Do not edit by hand — tests/Mcp/SkillManifestTest.php compares it with the server.',
                'tools' => array_values($registered),
            ];

            file_put_contents(
                self::MANIFEST,
                json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n",
            );
        }

        $documented = [];

        foreach (self::manifest()['tools'] as $tool) {
            self::assertIsArray($tool);
            self::assertIsString($tool['name']);
            $documented[$tool['name']] = $tool;
        }

        $problems = [];

        foreach (array_diff_key($registered, $documented) as $name => $_) {
            $problems[] = sprintf('- "%s" is registered but NOT in the skill manifest (undocumented tool).', $name);
        }

        foreach (array_diff_key($documented, $registered) as $name => $_) {
            $problems[] = sprintf('- "%s" is in the skill manifest but no longer registered (withdrawn tool — remove it from the skill).', $name);
        }

        foreach (array_intersect_key($registered, $documented) as $name => $tool) {
            if ($tool !== $documented[$name]) {
                $problems[] = sprintf(
                    "- \"%s\" changed:\n    registered: %s\n    manifest:   %s",
                    $name,
                    json_encode($tool, \JSON_UNESCAPED_SLASHES),
                    json_encode($documented[$name], \JSON_UNESCAPED_SLASHES),
                );
            }
        }

        self::assertSame([], $problems, "The MCP tools drifted from the wboost skill:\n" . implode("\n", $problems) . sprintf(self::HOW_TO_FIX, self::REGENERATE));
    }

    public function testEveryToolIsTaughtByTheSkill(): void
    {
        $skill = self::read(self::SKILL);
        $references = '';

        foreach ((array) glob(self::SKILL_DIRECTORY . '/references/*.md') as $file) {
            self::assertIsString($file);
            $references .= self::read($file) . "\n";
        }

        $problems = [];

        foreach (self::manifest()['tools'] as $tool) {
            self::assertIsArray($tool);
            $name = $tool['name'];
            self::assertIsString($name);

            if (preg_match('/^## `' . preg_quote($name, '/') . '\(/m', $references) !== 1) {
                $problems[] = sprintf('- "%s" has no "## `%s(…)`" reference section in references/*.md.', $name, $name);
            }

            if (!str_contains($skill, '`' . $name . '`')) {
                $problems[] = sprintf('- "%s" is never named (in backticks) in SKILL.md.', $name);
            }
        }

        self::assertSame([], $problems, "The wboost skill does not teach every tool:\n" . implode("\n", $problems) . sprintf(self::HOW_TO_FIX, self::REGENERATE));
    }

    public function testEveryReleaseMarkerCarriesTheSameVersion(): void
    {
        $plugin = self::json(self::PLUGIN_JSON);
        $marketplace = self::json(self::MARKETPLACE_JSON);
        self::assertIsArray($marketplace['plugins']);
        self::assertIsArray($marketplace['plugins'][0]);

        $skill = self::read(self::SKILL);
        preg_match('/^metadata:\n(?:[ \t]+.*\n)*?[ \t]+version:[ \t]*"?([0-9.]+)"?/m', $skill, $metadata);
        preg_match('/^## \[?(\d+\.\d+\.\d+)/m', self::read(self::CHANGELOG), $changelog);

        $versions = [
            'src/Mcp/Skill/SkillRelease.php VERSION' => SkillRelease::VERSION,
            'plugin/wboost/.claude-plugin/plugin.json "version"' => $plugin['version'] ?? null,
            '.claude-plugin/marketplace.json plugins[0].version' => $marketplace['plugins'][0]['version'] ?? null,
            'SKILL.md frontmatter metadata.version' => $metadata[1] ?? null,
            'references/tools.json "skillVersion"' => self::manifest()['skillVersion'] ?? null,
            'plugin/wboost/CHANGELOG.md top "## x.y.z" heading' => $changelog[1] ?? null,
        ];

        $mismatched = array_filter($versions, static fn (mixed $version): bool => $version !== SkillRelease::VERSION);

        self::assertSame([], $mismatched, sprintf(
            "Every skill release marker must say %s (SkillRelease::VERSION). Mismatched: %s\nA version bump is one change across all six — see docs/mcp/skill-maintenance.md.",
            SkillRelease::VERSION,
            json_encode($mismatched, \JSON_UNESCAPED_SLASHES),
        ));

        // The body tells Claude what to send — it must send THIS version.
        self::assertStringContainsString(sprintf('{"skillVersion": "%s"}', SkillRelease::VERSION), $skill, 'SKILL.md must tell Claude to pass {"skillVersion": "' . SkillRelease::VERSION . '"} on its first call.');
    }

    public function testTheSkillCanBeUploadedToClaudeAi(): void
    {
        $skill = self::read(self::SKILL);

        self::assertSame(1, preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $skill, $parts), 'SKILL.md must start with a --- frontmatter block.');

        preg_match_all('/^([A-Za-z_-]+):/m', $parts[1], $keys);
        $unexpected = array_values(array_diff($keys[1], self::UPLOADABLE_FRONTMATTER_KEYS));

        self::assertSame([], $unexpected, sprintf(
            'SKILL.md frontmatter has keys claude.ai refuses on upload ("Unexpected key(s) in SKILL.md frontmatter"): %s. Put extra data under metadata:.',
            implode(', ', $unexpected),
        ));

        self::assertSame(1, preg_match('/^name: wboost$/m', $parts[1]), 'The skill name must be "wboost" — it has to match its folder name.');
        self::assertSame(1, preg_match('/^description: (.+)$/m', $parts[1], $description));
        self::assertLessThanOrEqual(self::MAX_DESCRIPTION_LENGTH, mb_strlen($description[1]), 'The SKILL.md description is longer than the 1024 characters the skill spec allows.');

        self::assertLessThan(self::MAX_SKILL_BODY_LINES, substr_count($parts[2], "\n"), 'Keep SKILL.md under 500 lines — move detail into references/.');

        // Progressive disclosure: every reference SKILL.md points at exists.
        preg_match_all('/`?(references\/[A-Za-z0-9_.-]+)`?/', $skill, $links);

        foreach (array_unique($links[1]) as $link) {
            self::assertFileExists(self::SKILL_DIRECTORY . '/' . $link, sprintf('SKILL.md points at %s, which does not exist.', $link));
        }
    }

    public function testTheServerInstructionsFitAndLeadWithTheSkillVersion(): void
    {
        $client = self::createClient();
        TestingMcpClient::initialize($client, TestDataFixture::MCP_TOKEN_ACTIVE);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['result']);
        $instructions = $payload['result']['instructions'] ?? null;
        self::assertIsString($instructions);

        self::assertLessThanOrEqual(self::MAX_INSTRUCTIONS_LENGTH, mb_strlen($instructions), sprintf(
            'The MCP server instructions are %d characters; Claude Code shows only the first %d. Shorten config/packages/mcp.php — detail belongs in tool descriptions and the skill.',
            mb_strlen($instructions),
            self::MAX_INSTRUCTIONS_LENGTH,
        ));

        self::assertTrue(str_starts_with($instructions, SkillRelease::instructions()), 'The server instructions must open with SkillRelease::instructions().');
    }

    public function testTheVersionCheckTellsCurrentOutdatedAndMissingApart(): void
    {
        self::assertSame(SkillRelease::STATUS_CURRENT, SkillRelease::check(SkillRelease::VERSION)->status);
        self::assertNull(SkillRelease::check(SkillRelease::VERSION)->message);
        self::assertSame(SkillRelease::STATUS_CURRENT, SkillRelease::check('99.0.0')->status);

        $outdated = SkillRelease::check('0.0.1');
        self::assertSame(SkillRelease::STATUS_OUTDATED, $outdated->status);
        self::assertSame('0.0.1', $outdated->installedVersion);
        self::assertIsString($outdated->message);
        self::assertStringContainsString('claude plugin update wboost@wboost', $outdated->message);
        self::assertStringContainsString(SkillRelease::ZIP_URL, $outdated->message);

        foreach ([null, '', 'latest', '0.3'] as $missing) {
            $unknown = SkillRelease::check($missing);
            self::assertSame(SkillRelease::STATUS_UNKNOWN, $unknown->status);
            self::assertNull($unknown->installedVersion);
            self::assertIsString($unknown->message);
            self::assertStringContainsString('claude plugin install wboost@wboost', $unknown->message);
        }
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * Every registered tool (minus the test probes) in manifest shape, sorted
     * by name: its scope, required parameters, and each parameter's JSON-schema
     * type and enum. Descriptions are left out on purpose — rewording a tool's
     * docblock reaches clients straight from the server and needs no release.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function registeredManifestTools(): array
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var array<string, null|string> $scopes */
        $scopes = $container->getParameter(McpToolScopePass::PARAMETER);

        // Building the server runs the loaders that fill the registry; a token
        // holding every scope makes the scope filter list everything.
        $container->get('mcp.server');
        $token = new PostAuthenticationToken(new InMemoryUser('skill-manifest', null), 'mcp', ['ROLE_USER']);
        $token->setAttribute(McpScopeChecker::TOKEN_ATTRIBUTE, McpScope::values());
        $container->get('security.token_storage')->setToken($token);

        /** @var RegistryInterface $registry */
        $registry = $container->get('mcp.registry');

        $probes = self::fixtureTools();
        $tools = [];

        foreach ($registry->getTools()->references as $tool) {
            if (!$tool instanceof Tool || in_array($tool->name, $probes, true)) {
                continue;
            }

            $schema = $tool->inputSchema;
            $properties = $schema['properties'];
            $parameters = [];

            foreach ($properties as $parameter => $definition) {
                $definition = is_array($definition) ? $definition : [];
                $parameters[(string) $parameter] = array_filter([
                    'type' => $definition['type'] ?? null,
                    'enum' => $definition['enum'] ?? null,
                ], static fn (mixed $value): bool => $value !== null);
            }

            ksort($parameters);
            $required = array_values($schema['required'] ?? []);
            sort($required);

            $tools[$tool->name] = [
                'name' => $tool->name,
                'scope' => $scopes[$tool->name] ?? null,
                'required' => $required,
                'parameters' => $parameters === [] ? new \stdClass() : $parameters,
            ];
        }

        ksort($tools);

        // Round-trip through JSON so the comparison with the decoded file is
        // like for like ({} vs [] included).
        $normalized = json_decode(json_encode($tools, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($normalized);

        /** @var array<string, array<string, mixed>> $normalized */
        return $normalized;
    }

    /**
     * @return array{skillVersion?: mixed, tools: list<mixed>}
     */
    private static function manifest(): array
    {
        $manifest = self::json(self::MANIFEST);
        self::assertIsArray($manifest['tools'] ?? null, sprintf('%s has no "tools" list. Regenerate it: %s', self::MANIFEST, self::REGENERATE));

        /** @var array{skillVersion?: mixed, tools: list<mixed>} $manifest */
        return $manifest;
    }

    /**
     * The tool names declared by the probe classes in `tests/Mcp/Fixtures/`.
     *
     * @return list<string>
     */
    private static function fixtureTools(): array
    {
        $names = [];

        foreach ((array) glob(self::FIXTURE_TOOL_DIRECTORY . '/*.php') as $file) {
            if (!is_string($file)) {
                continue;
            }

            $class = 'WBoost\\Web\\Tests\\Mcp\\Fixtures\\' . basename($file, '.php');

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods() as $method) {
                foreach ($method->getAttributes(McpTool::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                    $names[] = $attribute->newInstance()->name ?? $method->getName();
                }
            }

            foreach ($reflection->getAttributes(McpTool::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $names[] = $attribute->newInstance()->name ?? $reflection->getShortName();
            }
        }

        return $names;
    }

    /**
     * @return array<mixed>
     */
    private static function json(string $path): array
    {
        $decoded = json_decode(self::read($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, sprintf('%s is not a JSON object.', $path));

        return $decoded;
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, sprintf('Could not read %s.', $path));

        return $contents;
    }
}
