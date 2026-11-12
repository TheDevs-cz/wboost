<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Template;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Print\PrintPdfRenderer;
use WBoost\Web\Services\SocialNetwork\ResolveElementOffsets;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Services\TemplateGroup\GroupFillRenderer;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * "Uživatel může posunout", M2 — a fill / render path that forgets the
 * user's moves exports the design where the user did NOT put it. Two nets,
 * like the repeatable-rows inventory ({@see RepeatableRowsEntryPointsTest}):
 *
 * 1. **Inventory (static):** every `src/` file that calls a renderer or a
 *    fill resolver either handles offsets itself (mentions them) or is on
 *    the DELEGATES list with the reason. A NEW call site fails until
 *    someone decides.
 * 2. **Behaviour:** the shared chokepoints forward the moves (group
 *    renderer per dimension, print passes), and the bake runs on the
 *    EXPANDED canvas — materialised rows ride along with a moved root.
 *    (The web pages are covered end to end by ElementOffsetsFillTest.)
 *
 * @covers \WBoost\Web\Services\SocialNetwork\ResolveElementOffsets
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 * @covers \WBoost\Web\Services\Print\PrintPdfRenderer
 */
final class ElementOffsetsEntryPointsTest extends KernelTestCase
{
    /**
     * Call sites that do NOT handle offsets themselves → why that is right.
     */
    private const array DELEGATES = [
        // The REST API carries `offsets` since M5 (ApiExportRenderer, strict),
        // the MCP fill tools since the M5 MCP half (VariantFill::withOffsets,
        // strict) — neither is listed here any more.
        'Mcp/Design/CandidateRenderer.php' => 'MCP design preview: the design itself, no fill',
        'Mcp/Tool/PreviewDesignTool.php' => 'MCP design preview: the design itself, no fill',
        'Services/AiScan/Template/TemplateProposal.php' => 'AI scan candidate: an unsaved design through CandidateRenderer, no fill',
        'Mcp/Tool/SetDesignTool.php' => 'MCP design preview: the design itself, no fill',
        // Pages arrive RESOLVED (overrides carry the offsets) — they only forward.
        'Services/Print/ScreenPdfRenderer.php' => 'forwards resolved overrides to the renderer',
        'Services/TemplateDocument/TemplateDocumentExporter.php' => 'forwards resolved overrides (GroupFillRenderer::resolve / ApiExportRenderer)',
        'Twig/ManualContentExtension.php' => 'not a template render (manual content renderer)',
    ];

    private const string CALL_PATTERN = '/->renderToBytes\(|[rR]enderer->render\(|resolveTextOverrides->resolve\(|resolveImageOverrides->resolve\(|->renderExport\(|->renderGroupMember\(|groupFillRenderer->resolve\(|apiExportRenderer->resolve\(|->forGroupValues\(|->forVariantValues\(|fill->texts\(|fill->images\(/';

    /** Every way a file can be offset-aware: the resolver, the override, the form keys, the API body. */
    private const string OFFSET_PATTERN = '/ResolveElementOffsets|withOffsets\(|rawOffsets|offsetValues|parseOffsets\(|\$overrides->offsets|\$data->offsets|\'offsets\'/';

    public function testEveryRenderAndResolveCallSiteHandlesOffsetsOrDelegates(): void
    {
        $srcDir = dirname(__DIR__, 3) . '/src';
        $found = [];

        foreach ((new Finder())->files()->in($srcDir)->name('*.php') as $file) {
            $contents = $file->getContents();
            if (preg_match(self::CALL_PATTERN, $contents) !== 1) {
                continue;
            }
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $found[$relative] = preg_match(self::OFFSET_PATTERN, $contents) === 1;
        }
        ksort($found);

        $unreviewed = [];
        foreach ($found as $relative => $handles) {
            if (!$handles && !isset(self::DELEGATES[$relative])) {
                $unreviewed[] = $relative;
            }
        }

        self::assertSame([], $unreviewed, 'New fill/render call sites: carry the user\'s element offsets there (ResolveElementOffsets / withOffsets) or list why they need not.');
        self::assertSame([], array_values(array_diff(array_keys(self::DELEGATES), array_keys($found))), 'Stale DELEGATES entries.');
        self::assertGreaterThanOrEqual(25, count($found), 'the scan must actually find the call sites');
    }

    public function testTheGroupRendererBakesEachDimensionsOwnMoves(): void
    {
        $variant = $this->movable();
        $renderer = $this->fakeRenderer();
        $group = self::getContainer()->get(GroupFillRenderer::class);

        $group->render($variant, [], [], [], rawOffsets: [
            $variant->id->toString() => [ContainerFixtures::TURNAJ_ROOT => ['x' => '0', 'y' => '-30']],
            Uuid::uuid7()->toString() => [ContainerFixtures::TURNAJ_ROOT => ['x' => '0', 'y' => '99']],
        ]);
        self::assertSame([ContainerFixtures::TURNAJ_ROOT => ['x' => 0.0, 'y' => -30.0]], $renderer->offsetCalls[0]);

        $group->render($variant, [], [], [], rawOffsets: [Uuid::uuid7()->toString() => [ContainerFixtures::TURNAJ_ROOT => ['x' => '0', 'y' => '99']]]);
        self::assertSame([], $renderer->offsetCalls[1], 'another dimension\'s move never leaks in');
    }

    public function testEveryPrintPassGetsTheMoves(): void
    {
        $variant = $this->movable();
        $renderer = $this->fakeRenderer();
        $offsets = [ContainerFixtures::TURNAJ_ROOT => ['x' => 0.0, 'y' => 40.0]];

        self::getContainer()->get(PrintPdfRenderer::class)->renderPage($variant, (new ResolvedInputOverrides([], []))->withOffsets($offsets), null);

        self::assertNotEmpty($renderer->offsetCalls);
        foreach ($renderer->offsetCalls as $i => $passOffsets) {
            self::assertSame($offsets, $passOffsets, sprintf('print pass %d', $i));
        }
    }

    /**
     * Rows first, then the moves: the bake runs on the EXPANDED canvas, so
     * every materialised row (derived ids) moves with its root — and the
     * stored design is never touched.
     */
    public function testTheBakeRunsAfterRowExpansion(): void
    {
        $variant = $this->movable();
        $stored = $variant->canvas;
        $expanded = RepeatableRows::expand($variant);
        $canvas = json_decode($expanded->canvas, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($canvas);

        $baked = ResolveElementOffsets::bake($canvas, 1080.0, 1350.0, [ContainerFixtures::TURNAJ_ROOT => ['x' => 0.0, 'y' => 40.0]]);

        $before = self::tops($canvas);
        $after = self::tops($baked);
        for ($slot = 0; $slot < 5; $slot++) {
            $id = RepeatableRows::slotId(ContainerFixtures::TURNAJ_ROW_1, ContainerFixtures::TURNAJ_SOUPER_1, $slot);
            self::assertArrayHasKey($id, $before, sprintf('row %d is materialised', $slot + 1));
            self::assertEqualsWithDelta($before[$id] + 40.0, $after[$id], 1e-9, sprintf('row %d moves with the root', $slot + 1));
        }
        self::assertSame($before[ContainerFixtures::TURNAJ_HEADLINE], $after[ContainerFixtures::TURNAJ_HEADLINE], 'a free element outside the root stays');
        self::assertSame($before[ContainerFixtures::TURNAJ_SESTAVA], $after[ContainerFixtures::TURNAJ_SESTAVA], 'another root stays (the template engine pushes it)');
        self::assertSame($stored, $variant->canvas);
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @param array<array-key, mixed> $canvas
     *
     * @return array<string, float>
     */
    private static function tops(array $canvas): array
    {
        $tops = [];
        self::assertIsArray($canvas['objects']);
        foreach ($canvas['objects'] as $object) {
            if (is_array($object) && is_string($object['inputId'] ?? null) && is_numeric($object['top'] ?? null)) {
                $tops[$object['inputId']] = (float) $object['top'];
            }
        }

        return $tops;
    }

    private function movable(): TemplateVariant
    {
        self::bootKernel();

        $raw = ContainerFixtures::turnajRepeatableRaw(max: 5, default: 2);
        /** @var list<array<string, mixed>> $containers */
        $containers = $raw['canvas']['containers'];
        foreach ($containers as $i => $container) {
            if ($container['id'] === ContainerFixtures::TURNAJ_ROOT) {
                $containers[$i]['userMove'] = ['vertical' => true, 'horizontal' => false, 'maxUp' => null, 'maxDown' => null, 'maxLeft' => null, 'maxRight' => null];
            }
        }
        $raw['canvas']['containers'] = $containers;

        return ContainerFixtures::variantFromRaw($raw, $this->template());
    }

    private function template(): Template
    {
        return self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID))
            ->template;
    }

    private function fakeRenderer(): FakeTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
