<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Template;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Mcp\Fill\VariantFill;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Print\PrintPdfRenderer;
use WBoost\Web\Services\Template\ApiExportRenderer;
use WBoost\Web\Services\Template\ExportVersionSeeder;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Services\TemplateGroup\GroupFillRenderer;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Value\ExportFillValues;
use WBoost\Web\Value\ExportFormat;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Risk #1 of the container-rows plan: a fill / render path that forgets to
 * expand repeatable rows silently drops a row's value or shows the wrong
 * rows. Two nets:
 *
 * 1. **Inventory (static):** every `src/` file that calls a renderer or a
 *    fill resolver is listed here — either it expands itself (mentions
 *    {@see RepeatableRows}) or it is on the DELEGATES list with the
 *    expanding chokepoint it goes through. A NEW call site fails this test
 *    until someone decides which it is.
 * 2. **Behaviour:** each expanding chokepoint, driven with a repeatable
 *    variant, resolves a value addressed to a DERIVED row id, starts new
 *    rows from the template row's sample, and hides the unused rows.
 *    (The web pages are covered end to end by RepeatableRowsFillTest.)
 *
 * @covers \WBoost\Web\Services\Template\RepeatableRows
 */
final class RepeatableRowsEntryPointsTest extends KernelTestCase
{
    /**
     * Call sites that do NOT expand themselves → the chokepoint that does.
     */
    private const array DELEGATES = [
        'Api/TemplateDocuments/TemplateDocumentRenderProcessor.php' => 'ApiExportRenderer::render() expands',
        'Api/TemplateGroups/GroupExportProcessor.php' => 'ApiExportRenderer::renderExport() expands',
        'Api/TemplateGroups/GroupPreviewProcessor.php' => 'ApiExportRenderer::renderGroupMember() → render() expands',
        'Api/Templates/ExportProcessor.php' => 'ApiExportRenderer::renderExport() expands',
        'Api/Templates/PreviewProcessor.php' => 'ApiExportRenderer::render() expands',
        'Controller/TemplateDocument/TemplateDocumentExportController.php' => 'GroupFillRenderer::resolve() expands; TemplateDocumentExporter renders through the renderer / PrintPdfRenderer belts',
        'Controller/TemplateGroup/TemplateGroupExportController.php' => 'GroupFillRenderer::renderExport() expands',
        'Controller/TemplateGroup/TemplateGroupExportVariantController.php' => 'GroupFillRenderer::renderExport() expands',
        'Mcp/Tool/ExportDocumentTool.php' => 'VariantFill::rows()/texts()/images()/withShapeColors() expand; renderer belt',
        'Mcp/Tool/ExportVariantTool.php' => 'VariantFill expands; renderer + PrintPdfRenderer belts',
        'Mcp/Tool/PreviewDesignTool.php' => 'CandidateRenderer expands',
        'Services/AiScan/Template/TemplateProposal.php' => 'CandidateRenderer expands',
        'Mcp/Tool/RenderVariantTool.php' => 'VariantFill expands; renderer belt',
        'Mcp/Tool/SetDesignTool.php' => 'CandidateRenderer expands',
        'Services/Print/ScreenPdfRenderer.php' => 'TemplateVariantImageRenderer::renderToBytes() belt (pages arrive resolved)',
        'Services/TemplateDocument/TemplateDocumentExporter.php' => 'renderer + PrintPdfRenderer belts (pages arrive resolved by GroupFillRenderer / ApiExportRenderer)',
        'Twig/ManualContentExtension.php' => 'not a template render (manual content renderer)',
    ];

    private const string CALL_PATTERN = '/->renderToBytes\(|[rR]enderer->render\(|resolveTextOverrides->resolve\(|resolveImageOverrides->resolve\(|->renderExport\(|->renderGroupMember\(|groupFillRenderer->resolve\(|apiExportRenderer->resolve\(|->forGroupValues\(|->forVariantValues\(|fill->texts\(|fill->images\(/';

    private const string ROW = ContainerFixtures::TURNAJ_ROW_1;
    private const string SOUPER = ContainerFixtures::TURNAJ_SOUPER_1;

    public function testEveryRenderAndResolveCallSiteExpandsOrDelegates(): void
    {
        $srcDir = dirname(__DIR__, 3) . '/src';
        $found = [];

        foreach ((new Finder())->files()->in($srcDir)->name('*.php') as $file) {
            if (preg_match(self::CALL_PATTERN, $file->getContents()) !== 1) {
                continue;
            }
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $found[$relative] = str_contains($file->getContents(), 'RepeatableRows');
        }
        ksort($found);

        $unreviewed = [];
        foreach ($found as $relative => $expands) {
            if (!$expands && !isset(self::DELEGATES[$relative])) {
                $unreviewed[] = $relative;
            }
        }

        self::assertSame([], $unreviewed, 'New fill/render call sites: expand repeatable rows there (RepeatableRows::expand) or list the expanding chokepoint they delegate to.');
        self::assertSame([], array_values(array_diff(array_keys(self::DELEGATES), array_keys($found))), 'Stale DELEGATES entries.');
        self::assertGreaterThanOrEqual(25, count($found), 'the scan must actually find the call sites');
    }

    public function testApiExportResolvesDerivedRowIdsAndShowsTheDefaultCount(): void
    {
        $variant = $this->repeatable();
        $renderer = $this->fakeRenderer();

        self::getContainer()->get(ApiExportRenderer::class)->render($variant, [$this->slot(self::SOUPER, 1) => 'TJ Kelč'], []);
        self::assertSame('TJ Kelč', $renderer->calls[0]['texts'][$this->slot(self::SOUPER, 1)] ?? null);
        $this->assertHidesFrom($renderer->hideCalls[0]['effectiveHidden'], 2, 'API: the default count (2)');

        // The strict resolver accepts the derived ids (not "unknown").
        [$overrides] = self::getContainer()->get(ApiExportRenderer::class)->resolve($variant, [$this->slot(self::SOUPER, 3) => 'x'], [], []);
        self::assertSame('x', $overrides->texts[$this->slot(self::SOUPER, 3)] ?? null);

        // The print PDF path renders through the same expansion.
        self::getContainer()->get(ApiExportRenderer::class)->renderExport(ExportFormat::Png, $variant, 'x', [], []);
        $this->assertHidesFrom($renderer->hideCalls[1]['effectiveHidden'], 2, 'API export');
    }

    public function testGroupRendererSharesTheCount(): void
    {
        $variant = $this->repeatable();
        $renderer = $this->fakeRenderer();

        self::getContainer()->get(GroupFillRenderer::class)->render($variant, [$this->slot(self::SOUPER, 3) => 'D'], [], [], rawRowCounts: [self::ROW => '4']);
        self::assertSame('D', $renderer->calls[0]['texts'][$this->slot(self::SOUPER, 3)] ?? null);
        self::assertSame([self::ROW => 4], $renderer->hideCalls[0]['rowCounts']);
        $this->assertHidesFrom($renderer->hideCalls[0]['effectiveHidden'], 4, 'group');
    }

    public function testMcpFillResolvesAgainstTheExpandedVariant(): void
    {
        $variant = $this->repeatable();
        $fill = self::getContainer()->get(VariantFill::class);

        $overrides = $fill->texts($variant, [$this->slot(self::SOUPER, 2) => 'MCP']);
        self::assertSame('MCP', $overrides->texts[$this->slot(self::SOUPER, 2)] ?? null);
        self::assertSame('Fotbal TJ Kelč', $overrides->texts[$this->slot(self::SOUPER, 4)] ?? null, 'sample of the template row');
    }

    public function testPrintRendererExpandsBeforeItsBlackInkScan(): void
    {
        $variant = $this->repeatable();
        $renderer = $this->fakeRenderer();
        $overrides = (new ResolvedInputOverrides([], []))->withRowCounts([self::ROW => 3]);

        self::getContainer()->get(PrintPdfRenderer::class)->renderPage($variant, $overrides, null);
        self::assertNotEmpty($renderer->calls);
        self::assertStringContainsString('repeatSlots', $renderer->calls[0]['canvas']);
        $this->assertHidesFrom($renderer->hideCalls[0]['effectiveHidden'], 3, 'print');
    }

    public function testSeederRestoresRowValuesAndClampsTheCount(): void
    {
        $variant = $this->repeatable();
        $seeder = self::getContainer()->get(ExportVersionSeeder::class);

        $values = ExportFillValues::fromVariantWebForm([$this->slot(self::SOUPER, 4) => 'pátý'], [], [])->withRows([self::ROW => 9]);
        $seed = $seeder->forVariantValues($values, $variant);
        self::assertSame([self::ROW => 5], $seed['rowCounts'], 'clamped to the CURRENT max');
        self::assertSame('pátý', $seed['textValues'][$this->slot(self::SOUPER, 4)] ?? null);

        $group = $seeder->forGroupValues($values, $variant->template->project->id, array_values(RepeatableRows::expand($variant)->inputs), [], [$variant]);
        self::assertSame([self::ROW => 5], $group['rowCounts']);
        self::assertSame('pátý', $group['texts'][$this->slot(self::SOUPER, 4)] ?? null);

        // A version without counts (recorded before rows existed) → defaults.
        self::assertSame([self::ROW => 2], $seeder->forVariantValues(ExportFillValues::fromArray([]), $variant)['rowCounts']);
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @param array<string, bool> $effectiveHidden
     */
    private function assertHidesFrom(array $effectiveHidden, int $count, string $message): void
    {
        for ($slot = 0; $slot < 5; $slot++) {
            self::assertSame(
                $slot >= $count,
                $effectiveHidden[$this->slot(self::SOUPER, $slot)] ?? false,
                sprintf('%s: row %d', $message, $slot + 1),
            );
        }
    }

    private function repeatable(): TemplateVariant
    {
        self::bootKernel();

        return ContainerFixtures::variantFromRaw(ContainerFixtures::turnajRepeatableRaw(max: 5, default: 2), $this->template());
    }

    private function template(): Template
    {
        return self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID))
            ->template;
    }

    private function slot(string $id, int $slot): string
    {
        return RepeatableRows::slotId(self::ROW, $id, $slot);
    }

    private function fakeRenderer(): FakeTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
