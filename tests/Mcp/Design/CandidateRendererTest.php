<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Design;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Sensiolabs\GotenbergBundle\GotenbergScreenshotInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Mcp\Design\CandidateRenderer;
use WBoost\Web\Mcp\Design\CompilationContext;
use WBoost\Web\Mcp\Design\CompiledDesign;
use WBoost\Web\Mcp\Design\DesignCompiler;
use WBoost\Web\Mcp\Design\DesignIdentity;
use WBoost\Web\Mcp\Design\Dsl\DslParser;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\BackgroundLayer;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\SocialNetwork\AssetInliner;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\ImagePlacement;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\Fakes\RecordingPreviewCache;
use WBoost\Web\Value\CanvasSlice;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\RenderImageFormat;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * S5-T1 — the seam that lets `preview_design` (S5-T2) show an agent a design it
 * has NOT saved.
 *
 * Almost everything asserted here is an ABSENCE, which is the awkward kind of
 * test to write and the only kind that matters for this class: the whole point
 * of a candidate render is that it changes nothing. So each test below states
 * the absence together with the positive control that keeps it from being
 * vacuous:
 *
 * - the DB row is unchanged **and** the render demonstrably used the candidate
 *   canvas (otherwise "nothing changed" is trivially true of a no-op);
 * - the preview cache is never consulted **and** the very same pool, on the very
 *   same renderer, IS consulted by an ordinary sliced render (otherwise
 *   "never consulted" only proves the pool was not wired in);
 * - the render fails through, unchanged, in both typed failure modes S5-T2 has
 *   to translate.
 */
final class CandidateRendererTest extends KernelTestCase
{
    /** The project's own faces — a compiled design may name no other. */
    private const string FONT = 'Rubik (Rubik Regular)';

    /**
     * The candidate — not the stored canvas — is what reaches the renderer.
     *
     * Without this the rest of the suite would pass on a seam that quietly
     * renders the persisted design: nothing would be written, nothing would be
     * cached, and the picture would be of the wrong thing.
     */
    public function testTheCandidateDesignIsRenderedAndNotTheStoredCanvas(): void
    {
        self::bootKernel();

        $variant = $this->variant();
        $design = $this->compile();
        $renderer = new FakeTemplateVariantImageRenderer();

        $this->candidateRenderer($renderer)->renderToBytes($variant, $design);

        self::assertCount(1, $renderer->calls);
        $call = $renderer->calls[0];

        self::assertSame($design->canvasJson(), $call['canvas'], 'the compiled canvas must be what gets drawn');
        self::assertNotSame($variant->canvas, $call['canvas'], 'the fixture must actually differ, or this proves nothing');

        // The inputs travel with the canvas: the renderer re-establishes the
        // positional textbox↔input binding from them, so a candidate rendered
        // with the STORED inputs would substitute the wrong texts into the
        // right boxes — a failure no pixel-level assertion would catch.
        self::assertSame(
            array_map(static fn (EditorTextInput $input): string => $input->inputId, $design->textInputs),
            $call['inputIds'],
        );
        self::assertSame(
            array_map(static fn (EditorImageInput $input): string => $input->inputId, $design->imageInputs),
            $call['imageInputIds'],
        );

        // Layer mode: `background_image` is a denormalized pointer to the
        // background LAYER's asset, and the canvas-save handler re-points it on
        // every write. The candidate mirrors that, so the preview and the
        // post-commit render agree about the picture behind everything.
        self::assertSame($design->backgroundAssetPath, $call['backgroundImage']);

        // And the identity that is NOT the design's to change.
        self::assertSame($variant->id->toString(), $call['variantId']);
    }

    /**
     * The variant row is untouched — canvas, thumbnail path and every other
     * column — and stays untouched through a `flush()`, which is the half a
     * before/after SELECT alone would miss: a mutation of the MANAGED entity
     * would sit in Doctrine's unit of work until something else in the request
     * flushed it, and only then become a silent overwrite of the user's design.
     */
    public function testTheStoredVariantRowIsUnchangedAndLeavesNothingPendingToFlush(): void
    {
        self::bootKernel();

        $variant = $this->variant();
        $before = $this->row();

        $this->candidateRenderer(new FakeTemplateVariantImageRenderer())
            ->renderToBytes($variant, $this->compile());

        self::assertSame($before, $this->row(), 'a candidate render must not write the variant row');

        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertSame($before, $this->row(), 'the managed entity must not even be DIRTY after a candidate render');

        // Named explicitly because the plan's done-when names them: the canvas
        // and the thumbnail pointer are what `set_design` (S5-T3) writes, and
        // this call is the one that must not.
        self::assertSame($variant->canvas, $before['canvas']);
        self::assertSame($variant->previewImagePath, $before['preview_image_path']);
    }

    /**
     * No `cache.gotenberg_preview` entry is written — because the pool is never
     * even consulted.
     *
     * The renderer only keys a render when it is a SLICE whose pixels are
     * provably independent of user input; a full render is never keyed and
     * never stored. {@see CandidateRenderer} has no `$slice` parameter and
     * passes none, so that branch is unreachable from it — this test pins the
     * consequence against the REAL renderer, and its control (an ordinary
     * sliced render on the same instance, which DOES hit the pool) is what
     * makes the zero meaningful rather than a wiring accident.
     */
    public function testThePreviewCacheIsNeverConsultedForACandidateRender(): void
    {
        self::bootKernel();

        $variant = $this->variant();
        $cache = new RecordingPreviewCache();
        $renderer = $this->realRendererThatExplodesIfItRenders($cache);

        try {
            $this->candidateRenderer($renderer)->renderToBytes($variant, $this->compile());

            self::fail('the stubbed Gotenberg should have failed this render.');
        } catch (RuntimeException $reachedGotenberg) {
            // Getting HERE is the point: the renderer went past the cache
            // decision and started building a screenshot, so the zero below is
            // "the pool was not consulted", not "the render never happened".
            self::assertStringContainsString('Gotenberg was called', $reachedGotenberg->getMessage());
        }

        self::assertSame([], $cache->lookups, 'a candidate render must never key the preview pool');
        self::assertSame([], $cache->invalidations, 'and must never invalidate a saved variant\'s renders either');
    }

    /**
     * The control for the test above, split out because it needs the render to
     * actually be attempted: a SLICED render of the very same variant, on the
     * very same renderer wiring, does consult the pool. Both halves share
     * {@see realRendererThatExplodesIfItRenders()}, so "the pool was not wired
     * in" cannot explain the zero next door.
     */
    public function testAnOrdinarySlicedRenderDoesConsultThatSamePool(): void
    {
        self::bootKernel();

        $variant = $this->variant();
        $cache = new RecordingPreviewCache('CONTROL-HIT');
        $renderer = $this->realRendererThatExplodesIfItRenders($cache);

        $bytes = $renderer->renderToBytes(
            $variant,
            new ResolvedInputOverrides([], []),
            new ResolvedImageOverrides([], []),
            slice: $this->overrideIndependentSlice($variant),
            format: RenderImageFormat::Png,
        );

        self::assertSame('CONTROL-HIT', $bytes);
        self::assertCount(1, $cache->lookups, 'a cacheable slice must go through the preview pool');
    }

    /**
     * Strictness and format are the caller's to choose, and travel through
     * untranslated.
     *
     * S5-T2 renders LENIENT (the picture must come back so the agent can see
     * the overflow it has to fix) while S5-T3 will want STRICT (a committed
     * design that falls off the page is a broken deliverable), so the flag
     * cannot be a policy baked into this class.
     */
    public function testStrictnessAndFormatAreHonouredAsPassed(): void
    {
        self::bootKernel();

        $variant = $this->variant();
        $design = $this->compile();
        $renderer = new FakeTemplateVariantImageRenderer();
        $candidateRenderer = $this->candidateRenderer($renderer);

        $candidateRenderer->renderToBytes($variant, $design, strictContainerOverflow: true, format: RenderImageFormat::Webp);
        $candidateRenderer->renderToBytes($variant, $design);

        self::assertTrue($renderer->calls[0]['strictContainerOverflow']);
        self::assertSame('webp', $renderer->calls[0]['format']);

        // The default is PNG, exactly as on the renderer it composes: WebP is
        // opt-in per call, never inherited.
        self::assertFalse($renderer->calls[1]['strictContainerOverflow']);
        self::assertSame('png', $renderer->calls[1]['format']);

        // Never a slice — the class docblock's cache argument rests on it.
        self::assertNull($renderer->calls[0]['slice']);
        self::assertNull($renderer->calls[1]['slice']);
    }

    /**
     * A container that overflows surfaces as {@see ContainerOverflow} itself,
     * carrying its container id and pixel count — S5-T2 turns those into the
     * sentence naming the inputs to shorten, and cannot do that with a
     * flattened error.
     */
    public function testContainerOverflowSurfacesAsItself(): void
    {
        self::bootKernel();

        $renderer = new FakeTemplateVariantImageRenderer();
        $renderer->throwContainerOverflow = new ContainerOverflow('container-1', 42.5);

        $this->expectException(ContainerOverflow::class);

        $this->candidateRenderer($renderer)
            ->renderToBytes($this->variant(), $this->compile(), strictContainerOverflow: true);
    }

    /**
     * An overloaded Gotenberg surfaces as {@see TemplateRenderUnavailable} —
     * "busy, retry", which is a different answer to the agent than "your design
     * is wrong" and must not be collapsed into one.
     */
    public function testRendererUnavailableSurfacesAsItself(): void
    {
        self::bootKernel();

        $renderer = new FakeTemplateVariantImageRenderer();
        $renderer->throwOnRender = TemplateRenderUnavailable::timedOut(new RuntimeException('gotenberg down'));

        $this->expectException(TemplateRenderUnavailable::class);

        $this->candidateRenderer($renderer)->renderToBytes($this->variant(), $this->compile());
    }

    /**
     * The end-to-end proof: a DSL document, parsed, compiled and rendered by
     * the REAL pipeline into real image bytes — with the preview pool watched
     * throughout.
     *
     * Excluded from the default suite (`phpunit.xml.dist` excludes the
     * `gotenberg` group) because it needs the container up; run it with
     * `vendor/bin/phpunit --group gotenberg`.
     */
    #[Group('gotenberg')]
    public function testACompiledDesignRendersToRealBytesWithoutTouchingAnything(): void
    {
        self::bootKernel();

        $variant = $this->variant();
        $before = $this->row();

        $cache = new RecordingPreviewCache();
        $renderer = $this->realRenderer($cache);

        $bytes = $this->candidateRenderer($renderer)->renderToBytes($variant, $this->compile());

        self::assertStringStartsWith("\x89PNG", $bytes, 'the default format is PNG and the bytes must say so');

        // The VARIANT supplies the geometry, the design supplies the content —
        // so a real render has to come out at the variant's own canvas size.
        $size = getimagesizefromstring($bytes);
        self::assertIsArray($size);
        self::assertSame([$variant->dimension->width(), $variant->dimension->height()], [$size[0], $size[1]]);

        self::assertSame([], $cache->lookups, 'a candidate render must not consult the preview pool');
        self::assertSame([], $cache->invalidations);
        self::assertSame($before, $this->row(), 'a real candidate render must still write nothing');
    }

    // =================================================================
    // helpers
    // =================================================================

    private function variant(): TemplateVariant
    {
        $repository = self::getContainer()->get(TemplateVariantRepository::class);

        // Layer mode, 1080x1080, and its project owns the Rubik faces the
        // design below names — the shape every design tool targets.
        return $repository->get(Uuid::fromString(TestDataFixture::ORIENTATION_VARIANT_ID));
    }

    /**
     * The persisted row, read straight from the database rather than from the
     * entity: an in-memory entity would happily report a value Doctrine has not
     * written (and, after a mutation, one it is about to).
     *
     * @return array<string, mixed>
     */
    private function row(): array
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $row = $connection->fetchAssociative(
            'SELECT canvas, preview_image_path, background_image, background_mode, inputs, image_inputs
             FROM template_variant WHERE id = ?',
            [TestDataFixture::ORIENTATION_VARIANT_ID],
        );

        self::assertIsArray($row);

        return $row;
    }

    private function candidateRenderer(TemplateVariantImageRendererInterface $renderer): CandidateRenderer
    {
        $container = self::getContainer();

        return new CandidateRenderer(
            $renderer,
            $container->get(ResolveTextOverrides::class),
            $container->get(ResolveRichTextOptions::class),
        );
    }

    /**
     * A small but complete design: a background-less canvas with two texts, one
     * of them carrying a `sampleValue` so the stand-in resolution this class
     * performs has something to do.
     */
    private function compile(): CompiledDesign
    {
        $document = DslParser::parse([
            'canvas' => ['width' => 1080, 'height' => 1080],
            'elements' => [
                [
                    'kind' => 'text', 'id' => 'headline', 'text' => 'Stand-in headline',
                    'font' => self::FONT, 'size' => 72, 'x' => 80, 'y' => 120, 'width' => 920,
                    'input' => ['name' => 'Headline', 'sampleValue' => 'Sample headline'],
                ],
                [
                    'kind' => 'text', 'id' => 'legal', 'text' => 'Small print',
                    'font' => self::FONT, 'size' => 24, 'x' => 80, 'y' => 900, 'width' => 920,
                    'input' => ['name' => 'Legal'],
                ],
            ],
        ]);

        return (new DesignCompiler(new BackgroundLayer()))->compile(
            $document,
            new CompilationContext(allowedFonts: [self::FONT], assets: []),
            DesignIdentity::fresh(),
        );
    }

    /**
     * A slice of the fixture's stored canvas that the renderer considers
     * cacheable — i.e. one holding no input-bound object. The layer-mode
     * background sits alone at stack index 0 and carries an image inputId, so
     * the decorative tail is used instead.
     */
    private function overrideIndependentSlice(TemplateVariant $variant): CanvasSlice
    {
        $decoded = json_decode($variant->canvas, true);
        self::assertIsArray($decoded);
        $objects = $decoded['objects'] ?? null;
        self::assertIsArray($objects);

        foreach ($objects as $index => $object) {
            self::assertIsInt($index);

            if (is_array($object) && ($object['inputId'] ?? null) === null) {
                return new CanvasSlice($index, $index + 1, withBackground: false);
            }
        }

        self::fail('the fixture canvas no longer has an unbound object to slice.');
    }

    private function realRenderer(RecordingPreviewCache $cache): TemplateVariantImageRenderer
    {
        /** @var GotenbergScreenshotInterface $gotenberg */
        $gotenberg = self::getContainer()->get(GotenbergScreenshotInterface::class);

        return $this->buildRenderer($gotenberg, $cache);
    }

    /**
     * The real renderer with Gotenberg replaced by a collaborator that throws.
     *
     * Both cache tests are about the CACHE DECISION, which the renderer makes
     * before it ever builds a screenshot — so paying for a headless render
     * would only make them slow and flaky. A call that gets past the decision
     * fails loudly instead of quietly succeeding.
     */
    private function realRendererThatExplodesIfItRenders(RecordingPreviewCache $cache): TemplateVariantImageRenderer
    {
        $gotenberg = $this->createStub(GotenbergScreenshotInterface::class);
        $gotenberg->method('html')->willThrowException(
            new RuntimeException('Gotenberg was called — the cache decision was not what this test measures.'),
        );

        return $this->buildRenderer($gotenberg, $cache);
    }

    private function buildRenderer(GotenbergScreenshotInterface $gotenberg, RecordingPreviewCache $cache): TemplateVariantImageRenderer
    {
        $container = self::getContainer();
        $geometry = new CanvasPlaceholderGeometry();
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        return new TemplateVariantImageRenderer(
            $gotenberg,
            $container->get(GetFonts::class),
            $container->get(AssetInliner::class),
            $geometry,
            new TextInputObjectBinder($geometry),
            new ImagePlacement(),
            $container->get(UploaderHelper::class),
            $projectDir . '/assets/fabric/fabric-7.3.1.min.js',
            $projectDir . '/assets/editor/fabric_break_word.js',
            $projectDir . '/assets/editor/container_layout.js',
            $projectDir . '/assets/editor/rich_text_runs.js',
            $projectDir . '/assets/editor/rich_text_blocks.js',
            $cache,
        );
    }
}
