<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Editor;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sensiolabs\GotenbergBundle\Exception\ClientException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use ReflectionMethod;
use Sensiolabs\GotenbergBundle\GotenbergScreenshotInterface;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\Image\NormalizeImageFormat;
use WBoost\Web\Services\SocialNetwork\AssetInliner;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\ImagePlacement;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\CanvasSlice;
use WBoost\Web\Value\EditorTextInput;

/**
 * Unit coverage for the renderer's canvas-JSON preparation — specifically the
 * inputId re-binding that heals variants whose canvas textboxes lost their
 * `inputId` custom property (Fabric v7 migration fallout: re-saved by a broken
 * editor build after the inputId migration ran) while inputs[] kept it.
 *
 * Without the re-bind, the render template's override-by-inputId lookup matches
 * nothing and placeholders render verbatim — exactly the reported "input value
 * is not applied in the export / preview" bug. The web/API tests cannot catch
 * this because they swap in a FakeRenderer that never builds the canvas JSON.
 *
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 */
final class TemplateVariantImageRendererTest extends TestCase
{
    public function testAlignTextboxInputIdsBindsTextboxesToInputsPositionally(): void
    {
        $canvas = [
            'objects' => [
                ['type' => 'Textbox', 'text' => 'Abc'],                          // migrated: no inputId
                ['type' => 'Image', 'src' => 'x'],                               // decorative: never an input
                ['type' => 'textbox', 'text' => 'Two', 'inputId' => 'stale-id'], // drifted id (lowercase type)
            ],
        ];

        $result = $this->invokeAlign($canvas, [
            $this->input('11111111-1111-4111-8111-111111111111'),
            $this->input('22222222-2222-4222-8222-222222222222'),
        ]);

        $objects = $result['objects'];
        self::assertIsArray($objects);

        // 1st Textbox ↔ inputs[0]: missing id stamped from the input.
        self::assertIsArray($objects[0]);
        self::assertSame('11111111-1111-4111-8111-111111111111', $objects[0]['inputId'] ?? null);

        // The image is skipped entirely — it is not part of inputs[].
        self::assertIsArray($objects[1]);
        self::assertArrayNotHasKey('inputId', $objects[1]);

        // 2nd Textbox ↔ inputs[1]: a drifted id is overwritten so the override
        // key (which comes from inputs[]) always resolves. Type match is
        // case-insensitive (Fabric v7 emits "Textbox", v5 emitted "textbox").
        self::assertIsArray($objects[2]);
        self::assertSame('22222222-2222-4222-8222-222222222222', $objects[2]['inputId'] ?? null);
    }

    public function testAlignTextboxInputIdsLeavesAlreadySyncedCanvasUnchanged(): void
    {
        $canvas = [
            'objects' => [
                ['type' => 'Textbox', 'text' => 'Abc', 'inputId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'],
            ],
        ];

        $result = $this->invokeAlign($canvas, [$this->input('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')]);

        self::assertSame($canvas, $result);
    }

    public function testAlignTextboxInputIdsIsNoOpWithoutObjects(): void
    {
        $canvas = ['version' => '5.2.4'];

        self::assertSame($canvas, $this->invokeAlign($canvas, [$this->input('11111111-1111-4111-8111-111111111111')]));
    }

    /**
     * @param array<string, mixed> $canvas
     * @param array<EditorTextInput> $inputs
     * @return array<string, mixed>
     */
    private function invokeAlign(array $canvas, array $inputs): array
    {
        // These collaborators are not exercised by the canvas-JSON prep path
        // under test, so they are inert stubs (not mocks with expectations).
        $geometry = new CanvasPlaceholderGeometry();
        $renderer = new TemplateVariantImageRenderer(
            $this->createStub(GotenbergScreenshotInterface::class),
            new GetFonts($this->createStub(EntityManagerInterface::class)),
            new AssetInliner($this->createStub(FilesystemReader::class), new NormalizeImageFormat()),
            $geometry,
            new TextInputObjectBinder($geometry),
            new ImagePlacement(),
            new UploaderHelper('http://assets.test/bucket'),
            '/nonexistent/fabric.js',
            '/nonexistent/fabric_break_word.js',
            '/nonexistent/container_layout.js',
            '/nonexistent/rich_text_runs.js',
            '/nonexistent/rich_text_blocks.js',
            new TagAwareAdapter(new ArrayAdapter()),
        );

        $method = new ReflectionMethod($renderer, 'alignTextboxInputIds');

        /** @var array<string, mixed> $result */
        $result = $method->invoke($renderer, $canvas, $inputs);

        return $result;
    }

    private function input(string $inputId): EditorTextInput
    {
        return new EditorTextInput($inputId, 'Name', null, false, false, null, false);
    }

    /**
     * The slice cache rests on ONE piece of reasoning: an object with no
     * `inputId` cannot be a CanvasContainer member (containers address members
     * by inputId), and suppression outside a slice is `opacity: 0` rather than
     * `visible: false` so nothing else reflows. Therefore a slice containing no
     * input-bound object cannot be changed by anything the user types, and is
     * safe to reuse across keystrokes.
     *
     * Everything this rule cannot prove must fall back to "render fresh".
     * These cases are the guard on that.
     *
     * @param list<mixed> $objects deliberately loose: one case feeds a
     *     malformed entry, which is exactly what the rule must refuse to
     *     reason about
     */
    #[DataProvider('sliceIndependenceCases')]
    public function testSliceIsOverrideIndependentOnlyWhenProvable(
        array $objects,
        CanvasSlice $slice,
        bool $expected,
        string $because,
    ): void {
        $canvas = json_encode(['objects' => $objects], JSON_THROW_ON_ERROR);

        $method = new ReflectionMethod(TemplateVariantImageRenderer::class, 'sliceIsOverrideIndependent');

        self::assertSame($expected, $method->invoke(null, $canvas, $slice), $because);
    }

    /**
     * @return iterable<string, array{list<mixed>, CanvasSlice, bool, string}>
     */
    public static function sliceIndependenceCases(): iterable
    {
        $logo = ['type' => 'Image', 'src' => 'logo.png'];
        $decor = ['type' => 'Rect'];
        $bound = ['type' => 'Textbox', 'inputId' => '11111111-1111-4111-8111-111111111111'];

        yield 'decorative overlay is cacheable' => [
            [$bound, $logo, $decor],
            new CanvasSlice(1, null, withBackground: false),
            true,
            'a logo + rect above the placeholder cannot change with typed text',
        ];

        yield 'slice containing a bound input is NOT cacheable' => [
            [$logo, $bound],
            new CanvasSlice(0, null, withBackground: false),
            false,
            'the slice paints an input the user can edit',
        ];

        yield 'bound input outside the slice does not block caching' => [
            [$bound, $logo],
            new CanvasSlice(1, null, withBackground: false),
            true,
            'objects outside the slice are opacity:0 and cannot reflow a non-member',
        ];

        yield 'empty inputId is treated as unbound' => [
            [['type' => 'Image', 'inputId' => '']],
            new CanvasSlice(0, null, withBackground: false),
            true,
            'an empty inputId binds nothing',
        ];

        yield 'malformed object is not reasoned about' => [
            ['not-an-object'],
            new CanvasSlice(0, null, withBackground: false),
            false,
            'unknown shape must fall back to rendering fresh',
        ];

        yield 'empty range is not cacheable' => [
            [$logo],
            new CanvasSlice(1, 1, withBackground: false),
            false,
            'an empty slice paints nothing worth caching',
        ];

        yield 'bounded toIndex stops the scan' => [
            [$logo, $decor, $bound],
            new CanvasSlice(0, 2, withBackground: false),
            true,
            'the bound input sits at index 2, outside [0,2)',
        ];
    }

    public function testReferencedFontFamiliesCollectsObjectAndOverrideFaces(): void
    {
        $canvas = json_encode([
            'objects' => [
                ['type' => 'Textbox', 'fontFamily' => 'Hero New (Hero New Super)'],
                ['type' => 'Image', 'src' => 'x'],
                ['type' => 'Textbox', 'fontFamily' => 'Hero New (Hero New ExtraBold)'],
            ],
        ]);
        self::assertNotFalse($canvas);

        $families = TemplateVariantImageRenderer::referencedFontFamilies(
            $canvas,
            ['Rubik (Rubik Bold)'], // a rich-text override run face
        );

        self::assertNotNull($families);
        sort($families);
        self::assertSame(
            ['Hero New (Hero New ExtraBold)', 'Hero New (Hero New Super)', 'Rubik (Rubik Bold)'],
            $families,
        );
    }

    public function testReferencedFontFamiliesCollectsNestedPerCharacterStyleFaces(): void
    {
        // Fabric serialized styles — both the object form ({line:{char:{...}}})
        // and the array form ([{start,end,style:{...}}]) must be scanned so a
        // per-character face is never dropped.
        $canvas = json_encode([
            'objects' => [
                [
                    'type' => 'Textbox',
                    'fontFamily' => 'Base (Base Regular)',
                    'styles' => ['0' => ['2' => ['fontFamily' => 'Base (Base Italic)']]],
                ],
                [
                    'type' => 'Textbox',
                    'styles' => [['start' => 0, 'end' => 3, 'style' => ['fontFamily' => 'Accent (Accent Bold)']]],
                ],
            ],
        ]);
        self::assertNotFalse($canvas);

        $families = TemplateVariantImageRenderer::referencedFontFamilies($canvas, []);

        self::assertNotNull($families);
        sort($families);
        self::assertSame(
            ['Accent (Accent Bold)', 'Base (Base Italic)', 'Base (Base Regular)'],
            $families,
        );
    }

    public function testReferencedFontFamiliesFallsBackToNullWhenUndetermined(): void
    {
        // Unparseable, no objects array, and text-free canvases all mean
        // "inline every face" — narrowing must never guess.
        self::assertNull(TemplateVariantImageRenderer::referencedFontFamilies('not json', []));
        self::assertNull(TemplateVariantImageRenderer::referencedFontFamilies('{}', []));
        self::assertNull(TemplateVariantImageRenderer::referencedFontFamilies(
            json_encode(['objects' => [['type' => 'Image', 'src' => 'x']]]) ?: '{}',
            [],
        ));
    }

    public function testSliceCanvasSuppressesOutOfRangeObjectsWithOpacityNotVisibility(): void
    {
        $canvas = [
            'backgroundImage' => ['type' => 'image', 'src' => 'http://assets.test/bucket/bg.png'],
            'objects' => [
                ['type' => 'Textbox', 'text' => 'below'],
                ['type' => 'image', 'src' => 'http://assets.test/bucket/deco.png', 'assetPath' => 'deco.png'],
                ['type' => 'Textbox', 'text' => 'above'],
            ],
        ];

        $result = TemplateVariantImageRenderer::sliceCanvas($canvas, new CanvasSlice(2, null, withBackground: false));

        // Transparent slice: the canvas-level background must not paint.
        self::assertArrayNotHasKey('backgroundImage', $result);

        $objects = $result['objects'];
        self::assertIsArray($objects);

        // Out-of-range objects: opacity 0 — NOT visible:false, which would
        // change the positional textbox binding and the container reflow.
        self::assertIsArray($objects[0]);
        self::assertSame(0, $objects[0]['opacity']);
        self::assertArrayNotHasKey('visible', $objects[0]);

        // Out-of-range image: src stubbed (no Minio fetch from headless
        // Chromium) and assetPath dropped (no re-inlining downstream).
        self::assertIsArray($objects[1]);
        self::assertSame(0, $objects[1]['opacity']);
        self::assertIsString($objects[1]['src']);
        self::assertStringStartsWith('data:image/png;base64,', $objects[1]['src']);
        self::assertArrayNotHasKey('assetPath', $objects[1]);

        // In-range object: untouched.
        self::assertIsArray($objects[2]);
        self::assertSame(['type' => 'Textbox', 'text' => 'above'], $objects[2]);
    }

    public function testSliceCanvasKeepsBackgroundAndBoundsTheRangeForTheBackdrop(): void
    {
        $canvas = [
            'backgroundImage' => ['type' => 'image', 'src' => 'bg.png'],
            'objects' => [
                ['type' => 'image', 'src' => 'below.png'],
                ['type' => 'image', 'src' => 'slot.png', 'imagePlaceholder' => true],
                ['type' => 'Textbox', 'text' => 'above'],
            ],
        ];

        $result = TemplateVariantImageRenderer::sliceCanvas($canvas, new CanvasSlice(0, 1, withBackground: true));

        self::assertArrayHasKey('backgroundImage', $result);

        $objects = $result['objects'];
        self::assertIsArray($objects);
        self::assertIsArray($objects[0]);
        self::assertArrayNotHasKey('opacity', $objects[0]);
        self::assertIsArray($objects[1]);
        self::assertSame(0, $objects[1]['opacity']);
        self::assertIsArray($objects[2]);
        self::assertSame(0, $objects[2]['opacity']);
    }

    /**
     * A busy renderer must be distinguishable from a broken render: the first
     * is a 503 the user retries, the second fails again identically. This is
     * the classification the 2026-08-05 overload turned into a PHP fatal.
     */
    public function testClassifiesClientTimeoutAsOverloaded(): void
    {
        $timedOut = new ClientException('Idle timeout reached', 0, new TransportException('Idle timeout reached'));

        self::assertTrue(TemplateVariantImageRenderer::isRendererOverloaded($timedOut));
    }

    public function testClassifiesGotenbergBusyStatusesAsOverloaded(): void
    {
        // 503 = Gotenberg's own --api-timeout ("context deadline exceeded"),
        // 429 = its Chromium queue is full.
        self::assertTrue(TemplateVariantImageRenderer::isRendererOverloaded(new ClientException('context deadline exceeded', 503)));
        self::assertTrue(TemplateVariantImageRenderer::isRendererOverloaded(new ClientException('gateway timeout', 504)));
        self::assertTrue(TemplateVariantImageRenderer::isRendererOverloaded(new ClientException('too many requests', 429)));
    }

    public function testDoesNotMistakeARenderErrorForOverload(): void
    {
        // 409 carries the container-overflow marker, 400/500 are genuine render
        // failures — retrying any of them changes nothing.
        self::assertFalse(TemplateVariantImageRenderer::isRendererOverloaded(new ClientException('CONTAINER_OVERFLOW:{}', 409)));
        self::assertFalse(TemplateVariantImageRenderer::isRendererOverloaded(new ClientException('bad request', 400)));
        self::assertFalse(TemplateVariantImageRenderer::isRendererOverloaded(new ClientException('context canceled', 500)));
    }
}
