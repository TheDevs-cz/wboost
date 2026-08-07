<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp;

use Mcp\Capability\Attribute\McpTool;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\DependencyInjection\McpToolScopePass;
use WBoost\Web\Exceptions\InvalidDesignDocument;
use WBoost\Web\Mcp\Design\Dsl\DslParser;
use WBoost\Web\Mcp\Design\Dsl\DslViolation;
use WBoost\Web\Mcp\Design\Dsl\ElementKind;
use WBoost\Web\Mcp\Design\Dsl\PlacementArea;
use WBoost\Web\Mcp\Design\Dsl\TextAlign;
use WBoost\Web\Mcp\Security\McpScope;

/**
 * The drift guard for the Claude Code skill under `plugin/wboost/skills/`.
 *
 * ## Why this test exists
 *
 * The skill is not documentation that a reader might or might not open — it is
 * loaded into an agent's context AS INSTRUCTIONS. That makes its failure mode
 * worse than a stale README's: an agent told "authoring designs is not
 * available through this connector" will not try, and no amount of the tool
 * actually existing will change its mind. Stale instructions are worse than
 * none, and they go stale silently, because nothing renders differently when a
 * tool ships.
 *
 * That is exactly what happened once already: three write tools
 * (`upload_image`, `preview_design`, `set_design`) landed while the skill went
 * on describing six read-only ones. This test is the thing that would have
 * caught it.
 *
 * ## What it asserts, and what it deliberately does not
 *
 * The skill is mostly PROSE — judgement about when to render versus export,
 * what a container overflow means, why `acknowledgeLosses` is not a retry flag.
 * Asserting on prose would make every wording improvement a failing build, so
 * nothing here reads a sentence. It asserts only the parts that ARE machine
 * facts, each against the code that owns it:
 *
 * | assertion | source of truth |
 * |---|---|
 * | the scope → tools table | {@see McpToolScopePass::PARAMETER} |
 * | every tool is named in `SKILL.md` | the same |
 * | the DSL element kinds | {@see ElementKind} |
 * | the accepted keys of every DSL block | {@see DslParser}'s `*_KEYS` |
 * | `at.area` / `align` values | {@see PlacementArea}, {@see TextAlign} |
 * | the worked design document | it is run through {@see DslParser} |
 *
 * The last row is the one worth pointing at: the skill's example is not
 * proof-read, it is PARSED. A grammar change that invalidates the document an
 * agent is being shown fails here, which is a stronger guarantee than any
 * amount of review.
 *
 * ## How a failure reads
 *
 * Each assertion names the file and the row to fix, and the diff is a set
 * difference — "the skill documents `fontSize`, the parser accepts `size`" —
 * rather than a whole-file mismatch. Editing prose, reordering sections or
 * rewording a table caption breaks nothing; adding a tool, changing a tool's
 * scope, or adding, removing or renaming a DSL key breaks exactly the row that
 * went wrong.
 */
final class SkillDocumentationTest extends KernelTestCase
{
    private const string SKILL = __DIR__ . '/../../plugin/wboost/skills/wboost/SKILL.md';

    private const string REFERENCE = __DIR__ . '/../../plugin/wboost/skills/wboost/references/tools.md';

    /**
     * Where the test-only probe tools live. Their names are READ from there
     * rather than copied, exactly as `McpGuideControllerTest` does it, so
     * adding a probe cannot fail this test and cannot quietly exempt a real
     * tool either.
     */
    private const string FIXTURE_TOOL_DIRECTORY = __DIR__ . '/Fixtures';

    /**
     * The first cell of a row in the reference's "Keys per block" table => the
     * parser constant that row must reproduce exactly.
     *
     * Keyed by the rendered label rather than by position: a reordered table
     * still passes, a renamed label fails loudly with the label it could not
     * find, and a new `*_KEYS` constant added to the parser without a row here
     * fails {@see testEveryParserKeySetIsDocumented()}.
     *
     * @var array<string, list<string>>
     */
    private const array GRAMMAR_BLOCKS = [
        'document root' => DslParser::ROOT_KEYS,
        '`canvas`' => DslParser::CANVAS_KEYS,
        '`canvas.background`' => DslParser::CANVAS_BACKGROUND_KEYS,
        '`at`' => DslParser::AT_KEYS,
        '`text` element' => DslParser::TEXT_KEYS,
        '`text` input block' => DslParser::TEXT_INPUT_KEYS,
        '`image` element' => DslParser::IMAGE_KEYS,
        '`image` input block' => DslParser::IMAGE_INPUT_KEYS,
        '`background` element' => DslParser::BACKGROUND_KEYS,
        '`container` element' => DslParser::CONTAINER_KEYS,
    ];

    /**
     * The scope table in the reference IS the registered tool/scope map. Both
     * directions matter: a tool missing from it is a capability the agent never
     * learns about, and a tool listed that no longer exists is an instruction
     * to call something that will fail.
     */
    public function testTheScopeTableMatchesTheRegisteredTools(): void
    {
        $documented = [];

        foreach (self::tableRows(self::read(self::REFERENCE)) as $row) {
            if (count($row) < 2) {
                continue;
            }

            $scope = McpScope::tryFrom(trim($row[0], '` '));

            if ($scope === null) {
                continue;
            }

            // Sorted, so the table stays free to list tools in the order a
            // human learns them rather than in the order a test finds tidy.
            $tools = self::backtickedTokens($row[1]);
            sort($tools);

            $documented[$scope->value] = $tools;
        }

        self::assertNotSame([], $documented, sprintf(
            'No scope rows found in %s — the "Scopes" table is the drift guard\'s anchor.',
            self::REFERENCE,
        ));

        $registered = [];

        foreach (self::shippedTools() as $tool => $scope) {
            if ($scope === null) {
                continue;
            }

            $registered[$scope][] = $tool;
        }

        foreach ($registered as $scope => $tools) {
            sort($tools);
            $registered[$scope] = $tools;
        }

        ksort($registered);
        ksort($documented);

        self::assertSame($registered, $documented, sprintf(
            'The scope table in %s no longer matches the registered MCP tools. '
            . 'A tool shipped, was withdrawn, or changed scope — update the table.',
            self::REFERENCE,
        ));
    }

    /**
     * The reference lists tools in a table; `SKILL.md` has to actually TEACH
     * them. A substring check is the right strength here: it cannot judge
     * whether the prose is any good, but it does catch the failure that
     * happened — a tool shipping and the skill never mentioning it.
     */
    public function testEveryShippedToolIsNamedInTheSkill(): void
    {
        $skill = self::read(self::SKILL);
        $tools = array_keys(self::shippedTools());

        self::assertNotSame([], $tools);

        foreach ($tools as $tool) {
            self::assertStringContainsString($tool, $skill, sprintf(
                'The MCP tool "%s" is registered but never named in %s.',
                $tool,
                self::SKILL,
            ));
        }
    }

    /**
     * The element kinds the reference documents are exactly the ones the parser
     * dispatches on — no missing kind an agent will never use, no invented one
     * whose documents are refused.
     */
    public function testTheDocumentedElementKindsMatchTheParser(): void
    {
        $documented = [];

        foreach (array_keys(self::GRAMMAR_BLOCKS) as $label) {
            if (!str_ends_with($label, ' element')) {
                continue;
            }

            $documented[] = trim(substr($label, 0, -strlen(' element')), '` ');
        }

        sort($documented);

        $kinds = ElementKind::values();
        sort($kinds);

        self::assertSame($kinds, $documented, sprintf(
            'The DSL element kinds documented in %s do not match ElementKind.',
            self::REFERENCE,
        ));

        self::assertSame($kinds, self::enumeratedValues('`kind`'), sprintf(
            'The "kind" row of the enumerated-values table in %s does not match ElementKind.',
            self::REFERENCE,
        ));
    }

    /**
     * The original S6-T3 "done when": the grammar section lists exactly the
     * keys the parser accepts, per block. Set equality in both directions — an
     * undocumented key is a feature agents never use, a documented one the
     * parser rejects is an instruction to write a document that is refused.
     */
    public function testTheDocumentedGrammarKeysMatchTheParser(): void
    {
        $rows = self::grammarRows();

        foreach (self::GRAMMAR_BLOCKS as $label => $keys) {
            self::assertArrayHasKey($label, $rows, sprintf(
                'The "Keys per block" table in %s has no row labelled %s.',
                self::REFERENCE,
                $label,
            ));

            $expected = $keys;
            sort($expected);

            $documented = $rows[$label];
            sort($documented);

            self::assertSame($expected, $documented, sprintf(
                'The documented keys for %s do not match the parser. Update the row in %s.',
                $label,
                self::REFERENCE,
            ));
        }
    }

    /**
     * Guards the map above from going stale in the direction it cannot see: a
     * new `*_KEYS` constant on the parser is a new grammar block, and it needs
     * a row here and in the reference.
     */
    public function testEveryParserKeySetIsDocumented(): void
    {
        $constants = (new ReflectionClass(DslParser::class))->getConstants();

        $keySets = array_filter(
            array_keys($constants),
            static fn (string $name): bool => str_ends_with($name, '_KEYS'),
        );

        self::assertNotSame([], $keySets);

        foreach ($keySets as $name) {
            self::assertContains($constants[$name], self::GRAMMAR_BLOCKS, sprintf(
                'DslParser::%s is a grammar key set with no row in %s (and none in this test\'s GRAMMAR_BLOCKS map).',
                $name,
                self::REFERENCE,
            ));
        }
    }

    /**
     * The two closed vocabularies an agent has to spell exactly.
     */
    public function testTheDocumentedEnumeratedValuesMatchTheParser(): void
    {
        $areas = PlacementArea::values();
        sort($areas);

        self::assertSame($areas, self::enumeratedValues('`at.area`'), sprintf(
            'The "at.area" row in %s does not match PlacementArea.',
            self::REFERENCE,
        ));

        $aligns = TextAlign::values();
        sort($aligns);

        self::assertSame($aligns, self::enumeratedValues('`align`'), sprintf(
            'The "align" row in %s does not match TextAlign.',
            self::REFERENCE,
        ));
    }

    /**
     * The worked example in `SKILL.md` is run through the real parser.
     *
     * An agent copies this document as its starting shape, so "it looks right"
     * is not good enough — a grammar change that invalidates it has to fail the
     * build. The example is the only ```json fence in the file (the fill-value
     * snippets are ```jsonc and are fragments, not documents), which is what
     * makes it findable without a marker comment.
     */
    public function testTheSkillsExampleDesignDocumentParses(): void
    {
        $fences = self::jsonFences(self::read(self::SKILL));

        self::assertCount(1, $fences, sprintf(
            'Expected exactly one ```json fence in %s (the worked design document). '
            . 'Fill-value fragments must stay ```jsonc so they are not parsed as documents.',
            self::SKILL,
        ));

        try {
            $document = DslParser::parseJson($fences[0]);
        } catch (InvalidDesignDocument $failure) {
            $problems = array_map(
                static fn (DslViolation $violation): string => sprintf('%s: %s', $violation->path, $violation->message),
                $failure->violations,
            );

            self::fail(sprintf(
                "The worked design document in %s no longer parses:\n  - %s",
                self::SKILL,
                implode("\n  - ", $problems),
            ));
        }

        // A document that parses but shows only one kind would teach half the
        // grammar; the example is there to exercise all four.
        $kinds = [];

        foreach ($document->elements as $element) {
            $kinds[] = $element->kind()->value;
        }

        $kinds = array_values(array_unique($kinds));
        sort($kinds);

        $all = ElementKind::values();
        sort($all);

        self::assertSame($all, $kinds, sprintf(
            'The worked design document in %s no longer exercises every element kind.',
            self::SKILL,
        ));
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * The first cell of every row in the "Keys per block" table => its
     * backticked keys.
     *
     * @return array<string, list<string>>
     */
    private static function grammarRows(): array
    {
        $rows = [];

        foreach (self::tableRows(self::read(self::REFERENCE)) as $row) {
            if (count($row) < 2) {
                continue;
            }

            $keys = self::backtickedTokens($row[1]);

            if ($keys === []) {
                continue;
            }

            $rows[trim($row[0])] = $keys;
        }

        return $rows;
    }

    /**
     * One row of the "Enumerated values" table, sorted.
     *
     * @return list<string>
     */
    private static function enumeratedValues(string $label): array
    {
        $rows = self::grammarRows();

        self::assertArrayHasKey($label, $rows, sprintf(
            'No row labelled %s in %s.',
            $label,
            self::REFERENCE,
        ));

        $values = $rows[$label];
        sort($values);

        return $values;
    }

    /**
     * Every markdown table row in a document, as trimmed cells. Separator rows
     * (`|---|---|`) are dropped.
     *
     * @return list<list<string>>
     */
    private static function tableRows(string $markdown): array
    {
        /** @var list<list<string>> $rows */
        $rows = [];

        foreach (explode("\n", $markdown) as $line) {
            $line = trim($line);

            if (!str_starts_with($line, '|') || !str_ends_with($line, '|')) {
                continue;
            }

            $cells = array_map(trim(...), explode('|', trim($line, '|')));

            if (preg_match('/^:?-{3,}:?$/', $cells[0]) === 1) {
                continue;
            }

            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * The `backticked` identifiers in a table cell, in order.
     *
     * @return list<string>
     */
    private static function backtickedTokens(string $cell): array
    {
        if (preg_match_all('/`([^`]+)`/', $cell, $matches) === false) {
            return [];
        }

        return $matches[1];
    }

    /**
     * The bodies of every ```json fence in a document.
     *
     * @return list<string>
     */
    private static function jsonFences(string $markdown): array
    {
        if (preg_match_all('/^```json\n(.*?)^```$/ms', $markdown, $matches) === false) {
            return [];
        }

        return $matches[1];
    }

    /**
     * The MCP tools this build registers, minus the test-only probes.
     *
     * @return array<string, null|string> tool name => scope value
     */
    private static function shippedTools(): array
    {
        self::bootKernel();

        /** @var array<string, null|string> $toolScopes */
        $toolScopes = self::getContainer()->getParameter(McpToolScopePass::PARAMETER);

        foreach (self::fixtureTools() as $probe) {
            unset($toolScopes[$probe]);
        }

        ksort($toolScopes);

        return $toolScopes;
    }

    /**
     * The tool names declared by the probe classes in `tests/Mcp/Fixtures/`,
     * read from their `#[McpTool]` attributes exactly as
     * {@see McpToolScopePass} reads them.
     *
     * @return list<string>
     */
    private static function fixtureTools(): array
    {
        /** @var list<string> $names */
        $names = [];

        foreach ((array) glob(self::FIXTURE_TOOL_DIRECTORY . '/*.php') as $file) {
            if (!is_string($file)) {
                continue;
            }

            /** @var class-string $class */
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

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);

        self::assertIsString($contents, sprintf('Could not read %s.', $path));

        return $contents;
    }
}
