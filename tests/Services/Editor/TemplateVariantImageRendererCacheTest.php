<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Editor;

use Ramsey\Uuid\Uuid;
use ReflectionMethod;
use RuntimeException;
use Sensiolabs\GotenbergBundle\GotenbergScreenshotInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Services\SocialNetwork\AssetInliner;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\ImagePlacement;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\CanvasSlice;
use WBoost\Web\Value\RenderImageFormat;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * The fill page renders 2-3 times per keystroke, and the transparent overlay
 * slices are usually byte-identical every time (they hold design content above
 * the placeholders, which no typed text can alter — see
 * {@see TemplateVariantImageRendererTest::testSliceIsOverrideIndependentOnlyWhenProvable()}
 * for that reasoning and its guard rails).
 *
 * This test proves the consequence the other one cannot: that `renderToBytes()`
 * really does short-circuit on a cache hit instead of calling Gotenberg again.
 * The Gotenberg collaborator here THROWS if it is touched, so reaching it fails
 * the test loudly rather than silently costing a render.
 *
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 */
final class TemplateVariantImageRendererCacheTest extends KernelTestCase
{
    public function testAnOverrideIndependentSliceIsServedFromCacheWithoutRendering(): void
    {
        self::bootKernel();

        $variant = $this->fixtureVariant();
        $slice = $this->firstOverlaySlice($variant);

        $cache = new TagAwareAdapter(new ArrayAdapter());
        $renderer = $this->rendererThatExplodesIfItRenders($cache);

        $key = $this->cacheKeyFor($renderer, $variant, $slice);
        self::assertIsString($key, 'the fixture overlay slice must be recognised as override-independent');

        // Seed the cache the way a previous keystroke's render would have.
        $item = $cache->getItem($key);
        $item->set('CACHED-BYTES');
        $cache->save($item);

        $bytes = $renderer->renderToBytes(
            $variant,
            new ResolvedInputOverrides([], [], []),
            new ResolvedImageOverrides([], []),
            slice: $slice,
            format: RenderImageFormat::Png,
        );

        self::assertSame('CACHED-BYTES', $bytes, 'the second render must come from cache, not Gotenberg');
    }

    /**
     * The same slice must NOT be considered cacheable once it contains an
     * input-bound object — the guard that keeps a user's typing visible.
     */
    public function testASliceCoveringABoundInputIsNeverCached(): void
    {
        self::bootKernel();

        $variant = $this->fixtureVariant();
        $cache = new TagAwareAdapter(new ArrayAdapter());
        $renderer = $this->rendererThatExplodesIfItRenders($cache);

        // Index 0 upward covers the whole stack, which includes the bound
        // textboxes this fixture's inputs are wired to.
        $wholeStack = new CanvasSlice(0, null, withBackground: true);

        self::assertNull(
            $this->cacheKeyFor($renderer, $variant, $wholeStack),
            'a slice containing editable inputs must always render fresh',
        );
    }

    private function fixtureVariant(): TemplateVariant
    {
        $repository = self::getContainer()->get(TemplateVariantRepository::class);

        return $repository->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));
    }

    private function firstOverlaySlice(TemplateVariant $variant): CanvasSlice
    {
        $geometry = new CanvasPlaceholderGeometry();
        $decoded = json_decode($variant->canvas, true);
        self::assertIsArray($decoded);

        $objects = $decoded['objects'] ?? [];
        self::assertIsArray($objects);

        $gaps = CanvasSlice::overlayGapsAbovePlaceholders(
            $objects,
            $geometry->placeholderObjectIndexesByInputId($decoded),
        );

        self::assertNotEmpty($gaps, 'fixture must still have design content above a placeholder');

        return $gaps[0]['slice'];
    }

    private function cacheKeyFor(TemplateVariantImageRenderer $renderer, TemplateVariant $variant, CanvasSlice $slice): null|string
    {
        $method = new ReflectionMethod($renderer, 'overrideIndependentCacheKey');

        $key = $method->invoke($renderer, $variant, new ResolvedImageOverrides([], []), false, $slice, RenderImageFormat::Png);
        self::assertTrue($key === null || is_string($key));

        return $key;
    }

    private function rendererThatExplodesIfItRenders(TagAwareAdapter $cache): TemplateVariantImageRenderer
    {
        // "Never called" IS the assertion of this test, so it is stated as an
        // expectation rather than inferred. The exception is belt-and-braces:
        // it makes an accidental call fail with a readable message instead of a
        // null-return TypeError deep in the builder chain.
        $gotenberg = $this->createMock(GotenbergScreenshotInterface::class);
        $gotenberg->expects(self::never())
            ->method('html')
            ->willThrowException(
                new RuntimeException('Gotenberg was called — the cache did not serve this render.'),
            );

        $container = self::getContainer();
        $geometry = new CanvasPlaceholderGeometry();

        $getFonts = $container->get(GetFonts::class);
        $assetInliner = $container->get(AssetInliner::class);
        $uploaderHelper = $container->get(UploaderHelper::class);

        $projectDir = (string) $container->getParameter('kernel.project_dir');

        return new TemplateVariantImageRenderer(
            $gotenberg,
            $getFonts,
            $assetInliner,
            $geometry,
            new TextInputObjectBinder($geometry),
            new ImagePlacement(),
            $uploaderHelper,
            $projectDir . '/assets/fabric/fabric-7.3.1.min.js',
            $projectDir . '/assets/editor/fabric_break_word.js',
            $projectDir . '/assets/editor/container_layout.js',
            $projectDir . '/assets/editor/rich_text_runs.js',
            $projectDir . '/assets/editor/rich_text_blocks.js',
            $cache,
        );
    }
}
