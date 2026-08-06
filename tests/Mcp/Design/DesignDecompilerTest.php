<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Design;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Mcp\Design\DecompilationContext;
use WBoost\Web\Mcp\Design\DecompiledDesign;
use WBoost\Web\Mcp\Design\DesignAsset;
use WBoost\Web\Mcp\Design\DesignCompiler;
use WBoost\Web\Mcp\Design\DesignDecompiler;
use WBoost\Web\Mcp\Design\DesignIdentity;
use WBoost\Web\Mcp\Design\DesignLoss;
use WBoost\Web\Mcp\Design\DesignLossCode;
use WBoost\Web\Mcp\Design\Dsl\CanvasSpec;
use WBoost\Web\Mcp\Design\Dsl\DslParser;
use WBoost\Web\Mcp\Design\Dsl\Placement;
use WBoost\Web\Mcp\Design\Dsl\PlacementArea;
use WBoost\Web\Mcp\Design\Dsl\Rect;
use WBoost\Web\Mcp\Design\Dsl\TextElement;
use WBoost\Web\Mcp\Design\Geometry\GridResolver;
use WBoost\Web\Services\Editor\BackgroundLayer;
use WBoost\Web\Value\BackgroundMode;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;

/**
 * {@see DesignDecompiler} at unit level — the properties `DesignRoundTripTest`
 * exercises in bulk over real data, pinned here one at a time so a failure
 * names its cause.
 *
 * The two that matter most, and why:
 *
 * - **Slug naming is {@see DesignIdentity::fromInputs()}'s, exactly.** Not
 *   "also uses `DesignSlug`" — byte-identical, including the duplicate-name
 *   case that is the only way two honest implementations diverge. Diverging
 *   means a `get_design → set_design` re-mints every `inputId`, and nothing
 *   throws when it does.
 * - **Semantic placement is emitted only when it reproduces the pixel.** An
 *   `at` that resolved to a slightly different rect would move the design a
 *   little on every save, forever.
 *
 * No kernel: the decompiler reads nothing but its arguments, and
 * {@see BackgroundLayer} (the compiler's one dependency) has none either.
 */
final class DesignDecompilerTest extends TestCase
{
    private const int CANVAS = 1200;

    // -----------------------------------------------------------------
    // identity
    // -----------------------------------------------------------------

    public function testSlugNamingIsByteIdenticalToDesignIdentityFromInputs(): void
    {
        // Two texts and an image all called "Text", plus an unnamed one and a
        // Czech name that has to transliterate: everything that decides who
        // keeps the bare slug.
        $textInputs = [
            self::textInput('a', 'Text'),
            self::textInput('b', 'Zaškrtávací seznam'),
            self::textInput('c', 'Text'),
            self::textInput('d', null),
        ];
        $imageInputs = [
            self::imageInput('e', 'Text'),
            self::imageInput('f', null),
        ];

        $canvas = ['objects' => [
            self::textbox(['inputId' => 'a']),
            self::textbox(['inputId' => 'b']),
            self::textbox(['inputId' => 'c']),
            self::textbox(['inputId' => 'd']),
            self::image(['inputId' => 'e', 'imagePlaceholder' => true]),
            self::image(['inputId' => 'f', 'imagePlaceholder' => true]),
        ]];

        $decompiled = self::decompile($canvas, $textInputs, $imageInputs);

        self::assertSame(
            DesignIdentity::fromInputs($textInputs, $imageInputs)->toArray(),
            $decompiled->inputIdsBySlug,
            'The decompiler names slugs differently from DesignIdentity::fromInputs(); a get_design → set_design would re-mint inputIds.',
        );

        self::assertSame(
            ['text', 'zaskrtavaci-seznam', 'text-2', 'text-3', 'text-4', 'image'],
            array_keys($decompiled->inputIdsBySlug),
        );
    }

    /**
     * The whole point of §4.1: a design read out and written straight back
     * keeps every `inputId`, so saved fills, API consumers and container
     * membership all still resolve.
     */
    public function testGetDesignThenSetDesignPreservesEveryInputId(): void
    {
        $textInputs = [self::textInput('11111111-1111-4111-8111-111111111111', 'Nadpis')];
        $imageInputs = [self::imageInput('22222222-2222-4222-8222-222222222222', 'Foto')];

        $canvas = ['objects' => [
            self::textbox(['inputId' => 'ignored-unreliable-value']),
            self::image(['inputId' => '22222222-2222-4222-8222-222222222222', 'imagePlaceholder' => true]),
            self::image(['inputId' => '33333333-3333-4333-8333-333333333333']),
        ]];

        $decompiled = self::decompile($canvas, $textInputs, $imageInputs);
        $compiled = (new DesignCompiler(new BackgroundLayer()))->compile(
            $decompiled->document,
            CanvasComparison::compilationContext($decompiled->document, []),
            $decompiled->identity(),
        );

        self::assertSame(
            ['11111111-1111-4111-8111-111111111111'],
            array_map(static fn (EditorTextInput $input): string => $input->inputId, $compiled->textInputs),
            'The text input lost its id — note the canvas object carried a DIFFERENT one, and inputs[] is the authority (TextInputObjectBinder).',
        );
        self::assertSame(
            ['22222222-2222-4222-8222-222222222222'],
            array_map(static fn (EditorImageInput $input): string => $input->inputId, $compiled->imageInputs),
        );

        // The decorative image has no input row at all, and its id still has
        // to survive: containers address members by it.
        self::assertSame(
            '33333333-3333-4333-8333-333333333333',
            $compiled->objects()[2]['inputId'] ?? null,
        );
    }

    public function testDuplicateInputIdsAreReportedRatherThanSilentlyRemapped(): void
    {
        $canvas = ['objects' => [
            self::image(['inputId' => 'dupe']),
            self::image(['inputId' => 'dupe']),
        ]];

        $decompiled = self::decompile($canvas, [], []);

        self::assertSame([DesignLossCode::IdentityRemapped], self::codes($decompiled));
        self::assertCount(1, $decompiled->inputIdsBySlug);
    }

    // -----------------------------------------------------------------
    // placement
    // -----------------------------------------------------------------

    public function testSemanticPlacementIsEmittedWhenTheElementSitsOnTheGrid(): void
    {
        $canvas = ['objects' => [
            // The whole top third, full width.
            self::textbox(['left' => 0, 'top' => 0, 'width' => self::CANVAS]),
            // Columns 2..11 of the same band — the grid edges of a 1200 px
            // canvas fall on every 100 px.
            self::textbox(['left' => 100, 'top' => 0, 'width' => 1000]),
        ]];

        $document = self::decompile($canvas, [], [])->document;
        $elements = $document->textElements();

        self::assertNotNull($elements[0]->placement->at);
        self::assertSame(PlacementArea::Top, $elements[0]->placement->at->area);
        self::assertSame([1, 12], [$elements[0]->placement->at->colStart, $elements[0]->placement->at->colEnd]);
        self::assertNull($elements[0]->placement->x, 'A semantic placement must not also carry absolute pixels.');

        self::assertNotNull($elements[1]->placement->at);
        self::assertSame([2, 11], [$elements[1]->placement->at->colStart, $elements[1]->placement->at->colEnd]);
    }

    public function testGeometryOffTheGridFallsBackToAbsolutePixels(): void
    {
        $canvas = ['objects' => [
            self::textbox(['left' => 137.5, 'top' => 55.25, 'width' => 402.75]),
        ]];

        $element = self::decompile($canvas, [], [])->document->textElements()[0];

        self::assertNull($element->placement->at, 'A hand-placed element must not be dressed up as grid-placed.');
        self::assertSame([137.5, 55.25, 402.75], array_slice(self::coordinates($element->placement), 0, 3));
    }

    public function testAnImageTakesItsBandHeightOnlyWhenTheBandActuallyMatches(): void
    {
        $canvas = ['objects' => [
            // Exactly the middle third: the band supplies the height.
            self::image(['left' => 0, 'top' => 400, 'width' => self::CANVAS, 'height' => 400]),
            // Same band, shorter: the height has to be written out.
            self::image(['left' => 0, 'top' => 400, 'width' => self::CANVAS, 'height' => 300]),
        ]];

        $elements = self::decompile($canvas, [], [])->document->imageElements();

        self::assertSame(PlacementArea::Middle, $elements[0]->placement->at?->area);
        self::assertNull($elements[0]->placement->height);

        self::assertSame(PlacementArea::Middle, $elements[1]->placement->at?->area);
        self::assertSame(300.0, $elements[1]->placement->height);
    }

    /**
     * A scaled textbox has no DSL representation as such — but a UNIFORMLY
     * scaled one is the same pixels as an unscaled one with a wider wrap and a
     * bigger font, which the DSL can say exactly. So it is flattened, not lost.
     */
    public function testAUniformlyScaledTextboxIsFlattenedIntoItsWidthAndFontSize(): void
    {
        $canvas = ['objects' => [
            self::textbox(['left' => 0, 'top' => 0, 'width' => 300, 'fontSize' => 20, 'scaleX' => 2, 'scaleY' => 2]),
        ]];

        $decompiled = self::decompile($canvas, [], []);
        $element = $decompiled->document->textElements()[0];

        self::assertSame(600.0, self::rect($element->placement)->width);
        self::assertSame(40.0, $element->size);
        self::assertSame([], $decompiled->losses, 'A uniform scale is expressible; nothing is lost.');
    }

    // -----------------------------------------------------------------
    // losses
    // -----------------------------------------------------------------

    public function testAnObjectKindTheDslHasNoWordForIsDroppedAndReported(): void
    {
        $canvas = ['objects' => [
            ['type' => 'Rect', 'left' => 0, 'top' => 0, 'width' => 100, 'height' => 100],
            self::textbox([]),
        ]];

        $decompiled = self::decompile($canvas, [], []);

        self::assertCount(1, $decompiled->document->elements);
        self::assertSame([DesignLossCode::ObjectDropped], self::codes($decompiled));
        self::assertStringContainsString('"Rect"', $decompiled->losses[0]->message);
        self::assertTrue($decompiled->losses[0]->destructive);
    }

    public function testADesignHiddenLayerIsDroppedWithoutConsumingAnInputSlot(): void
    {
        $textInputs = [self::textInput('a', 'Nadpis'), self::textInput('b', 'Popisek')];

        $canvas = ['objects' => [
            self::textbox(['left' => 0, 'top' => 0]),
            self::textbox(['left' => 0, 'top' => 500, 'visible' => false]),
            self::textbox(['left' => 0, 'top' => 900]),
        ]];

        $decompiled = self::decompile($canvas, $textInputs, []);

        // Plan §4.1-3: the hidden textbox is not in inputs[], so it must not
        // shift the binding of everything after it.
        self::assertSame(
            ['nadpis' => 'a', 'popisek' => 'b'],
            $decompiled->inputIdsBySlug,
        );
        self::assertSame([DesignLossCode::ObjectDropped], self::codes($decompiled));
    }

    public function testAVisibleBackgroundBelowTheTopOfTheStackIsReportedAsRestacked(): void
    {
        $canvas = ['objects' => [
            self::image(['left' => 0, 'top' => 0, 'assetPath' => 'gallery/logo.png']),
            self::image(['isBackground' => true, 'left' => 0, 'top' => 0, 'assetPath' => 'gallery/bg.png']),
        ]];

        $decompiled = self::decompile($canvas, [], [], self::context());

        self::assertSame([DesignLossCode::ObjectRestacked], self::codes($decompiled));
        self::assertSame('background', $decompiled->document->elements[0]->id, 'The background must lead the document, since the compiler pins it to stack index 0.');
    }

    public function testTransformsAndPaintingTheDslCannotCarryAreReported(): void
    {
        $canvas = ['objects' => [
            self::textbox([
                'angle' => 12,
                'styles' => [['start' => 0, 'end' => 3, 'style' => ['fill' => '#ff0000']]],
                'underline' => true,
                'charSpacing' => 40,
                'fill' => ['type' => 'linear'],
            ]),
            self::image(['flipX' => true, 'cropX' => 20, 'filters' => ['grayscale'], 'opacity' => 0.5, 'editorLocked' => true]),
        ]];

        $decompiled = self::decompile($canvas, [], []);

        self::assertSame(
            [DesignLossCode::StyleDropped, DesignLossCode::TransformDropped],
            self::codes($decompiled),
        );
        self::assertGreaterThanOrEqual(8, count($decompiled->losses), 'Each unrepresentable property gets its own sentence.');
        self::assertSame('#000000', $decompiled->document->textElements()[0]->color, 'A gradient fill degrades to the default colour.');
    }

    public function testTheListAndChecklistInputStackIsReportedRatherThanQuietlyDisabled(): void
    {
        $canvas = ['objects' => [self::textbox([]), self::textbox([])]];

        $decompiled = self::decompile($canvas, [
            new EditorTextInput('a', 'Odrážky', null, false, false, null, false, richText: true, lists: true),
            new EditorTextInput('b', 'Seznam', null, false, false, 'Nápověda', false, richText: true, lists: true, listCheckboxes: true, checklist: true),
        ], []);

        self::assertSame([DesignLossCode::InputFeatureDropped], self::codes($decompiled));

        $messages = implode("\n", array_map(static fn (DesignLoss $loss): string => $loss->message, $decompiled->losses));
        self::assertStringContainsString('bulleted and numbered lists', $messages);
        self::assertStringContainsString('CHECKLIST component', $messages);
        self::assertStringContainsString('description', $messages);
    }

    public function testALegacyCanvasModeBackgroundIsReportedAsNonDestructive(): void
    {
        $canvas = ['objects' => [self::textbox([])]];

        $decompiled = $this->decompiler()->decompile(
            $canvas,
            [],
            [],
            self::CANVAS,
            self::CANVAS,
            BackgroundMode::Canvas,
            DecompilationContext::empty(),
        );

        self::assertSame([DesignLossCode::CanvasFeatureDropped], self::codes($decompiled));
        self::assertFalse($decompiled->losses[0]->destructive, 'A canvas-level background survives a set_design untouched — it lives on the variant row.');
        self::assertSame([], $decompiled->destructiveLosses());
    }

    public function testAPictureThatIsNotAGalleryRowIsReportedAsUnnameable(): void
    {
        $canvas = ['objects' => [
            self::image(['isBackground' => true, 'assetPath' => 'custom-templates/019f/background-123.png']),
        ]];

        $decompiled = self::decompile($canvas, [], []);

        self::assertSame([DesignLossCode::AssetUnresolved], self::codes($decompiled));
        self::assertStringContainsString('NO background', $decompiled->losses[0]->message);
    }

    /**
     * The compiler refuses a fillable background with no picture (there would
     * be no object to carry the flag). A decompiler that emitted one anyway
     * would hand the agent a document that cannot compile.
     */
    public function testAFillableBackgroundWhosePictureIsUnnameableLosesItsFillableFlag(): void
    {
        $canvas = ['objects' => [
            self::image(['isBackground' => true, 'imagePlaceholder' => true, 'assetPath' => 'custom-templates/019f/bg.png']),
        ]];

        $decompiled = self::decompile($canvas, [], []);
        $element = $decompiled->document->backgroundElement();

        self::assertNotNull($element);
        self::assertNull($element->assetId);
        self::assertFalse($element->fillable);
    }

    // -----------------------------------------------------------------
    // containers
    // -----------------------------------------------------------------

    public function testContainerMembershipIsTranslatedToSlugsAndSanitizedToWhatTheParserAccepts(): void
    {
        $textInputs = [self::textInput('t1', 'Nadpis'), self::textInput('t2', 'Popisek')];
        $imageInputs = [self::imageInput('img', 'Foto')];

        $canvas = [
            'objects' => [
                self::textbox(['top' => 0]),
                self::textbox(['top' => 100]),
                self::image(['inputId' => 'img', 'imagePlaceholder' => true, 'top' => 200]),
            ],
            'containers' => [
                // A fillable placeholder may never flow in a container
                // (§4.4-18) and the parser refuses one by slug.
                ['id' => 'body', 'maxHeight' => 400, 'memberInputIds' => ['t1', 't2', 'img']],
                // Left with one member once the unknown one is dropped.
                ['id' => 'stray', 'maxHeight' => 200, 'memberInputIds' => ['t1', 'nobody']],
            ],
        ];

        $decompiled = self::decompile($canvas, $textInputs, $imageInputs);
        $containers = $decompiled->document->containerElements();

        self::assertCount(1, $containers);
        self::assertSame('body', $containers[0]->id);
        self::assertSame(['nadpis', 'popisek'], $containers[0]->memberIds);
        self::assertSame([DesignLossCode::InputFeatureDropped], self::codes($decompiled));

        // And the whole document must still parse — a container the parser
        // rejects would turn a lossy design into a broken tool response.
        self::assertSame(
            $decompiled->document->toArray(),
            DslParser::parse($decompiled->document->toArray())->toArray(),
        );
    }

    // -----------------------------------------------------------------
    // fit rules (the compiler's, inverted)
    // -----------------------------------------------------------------

    public function testAPlaceholderReportsItsFrameAndADecorativeImageItsDisplayedBox(): void
    {
        $canvas = ['objects' => [
            // Placeholder: the frame IS the slot, whatever the picture's ratio.
            self::image([
                'inputId' => 'slot', 'imagePlaceholder' => true,
                'left' => 100, 'top' => 100, 'width' => 800, 'height' => 600,
                'scaleX' => 0.5, 'scaleY' => 0.25, 'assetPath' => 'gallery/pic.png',
            ]),
            // Decorative: uniformly scaled, so its displayed box already has
            // the picture's ratio and contain-fitting it again is the identity.
            self::image([
                'left' => 0, 'top' => 0, 'width' => 400, 'height' => 200,
                'scaleX' => 0.5, 'scaleY' => 0.5, 'assetPath' => 'gallery/logo.png',
            ]),
        ]];

        $decompiled = self::decompile($canvas, [], [self::imageInput('slot', 'Foto')], self::context());
        $images = $decompiled->document->imageElements();

        self::assertSame([100.0, 100.0, 400.0, 150.0], self::coordinates($images[0]->placement));
        self::assertSame([0.0, 0.0, 200.0, 100.0], self::coordinates($images[1]->placement));
        self::assertSame([], $decompiled->losses);
    }

    public function testANonUniformlyStretchedDecorativeImageIsReported(): void
    {
        $canvas = ['objects' => [
            self::image(['left' => 0, 'top' => 0, 'width' => 400, 'height' => 200, 'scaleX' => 1, 'scaleY' => 2, 'assetPath' => 'gallery/logo.png']),
        ]];

        $decompiled = self::decompile($canvas, [], [], self::context());

        self::assertSame([DesignLossCode::TransformDropped], self::codes($decompiled));
        self::assertStringContainsString('contain-fits', $decompiled->losses[0]->message);
    }

    public function testADecorativeImageKeepsItsDesignerNameThroughAnOptedOutInputBlock(): void
    {
        $canvas = ['objects' => [
            self::image(['name' => 'Logo', 'assetPath' => 'gallery/logo.png']),
        ]];

        $element = self::decompile($canvas, [], [], self::context())->document->imageElements()[0];

        self::assertNotNull($element->input);
        self::assertSame('Logo', $element->input->name);
        self::assertFalse($element->input->placeholder, 'Naming a decorative image must not make it fillable.');
    }

    // -----------------------------------------------------------------
    // defaults
    // -----------------------------------------------------------------

    /**
     * A canvas that omits a text property renders with Fabric's default, so
     * emitting that default reproduces the canvas exactly and nothing is lost.
     * (The font then fails at COMPILE time, with the project's face list
     * attached — plan §4.2-10 — which is the message worth giving.)
     */
    public function testMissingTextPropertiesDecompileToFabricsOwnDefaults(): void
    {
        $canvas = ['objects' => [['type' => 'Textbox', 'left' => 0, 'top' => 0, 'width' => 400]]];

        $decompiled = self::decompile($canvas, [], []);
        $element = $decompiled->document->textElements()[0];

        self::assertSame('Times New Roman', $element->font);
        self::assertSame(40.0, $element->size);
        self::assertSame(TextElement::DEFAULT_COLOR, $element->color);
        self::assertSame(TextElement::DEFAULT_LINE_HEIGHT, $element->lineHeight);
        self::assertSame('', $element->text);
        self::assertSame([], $decompiled->losses);
    }

    public function testFabricRgbColoursAreNormalizedToTheDslsHexNotation(): void
    {
        $canvas = ['objects' => [
            self::textbox(['fill' => 'rgb(200,16,46)']),
            self::textbox(['fill' => 'rgba(0,78,124,1)']),
            self::textbox(['fill' => 'rgba(0,0,0,0.5)']),
        ]];

        $decompiled = self::decompile($canvas, [], []);
        $elements = $decompiled->document->textElements();

        self::assertSame('#c8102e', $elements[0]->color);
        self::assertSame('#004e7c', $elements[1]->color);
        self::assertSame('#000000', $elements[2]->color, 'The DSL has no alpha; a translucent fill degrades and is reported.');
        self::assertSame([DesignLossCode::StyleDropped], self::codes($decompiled));
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function decompiler(): DesignDecompiler
    {
        return new DesignDecompiler();
    }

    /**
     * Resolve a placement the way the compiler will — semantic or absolute,
     * the caller should not have to care which the decompiler chose.
     */
    private static function rect(Placement $placement): Rect
    {
        return GridResolver::resolvePlacement($placement, new CanvasSpec(self::CANVAS, self::CANVAS));
    }

    /**
     * @return array{float, float, float, null|float}
     */
    private static function coordinates(Placement $placement): array
    {
        $rect = self::rect($placement);

        return [$rect->x, $rect->y, $rect->width, $rect->height];
    }

    /**
     * @param array<array-key, mixed> $canvas
     * @param list<EditorTextInput> $textInputs
     * @param list<EditorImageInput> $imageInputs
     */
    private static function decompile(
        array $canvas,
        array $textInputs,
        array $imageInputs,
        null|DecompilationContext $context = null,
    ): DecompiledDesign {
        return (new DesignDecompiler())->decompile(
            $canvas,
            $textInputs,
            $imageInputs,
            self::CANVAS,
            self::CANVAS,
            BackgroundMode::Layer,
            $context ?? DecompilationContext::empty(),
        );
    }

    /**
     * Two gallery pictures, both 400 x 200 — enough for the fit inversions and
     * for the background cover check.
     */
    private static function context(): DecompilationContext
    {
        return new DecompilationContext([
            'gallery/logo.png' => new DesignAsset('44444444-4444-4444-8444-444444444444', 'gallery/logo.png', 'https://example.invalid/gallery/logo.png', 400, 200),
            'gallery/pic.png' => new DesignAsset('55555555-5555-4555-8555-555555555555', 'gallery/pic.png', 'https://example.invalid/gallery/pic.png', 800, 600),
            'gallery/bg.png' => new DesignAsset('66666666-6666-4666-8666-666666666666', 'gallery/bg.png', 'https://example.invalid/gallery/bg.png', self::CANVAS, self::CANVAS),
        ]);
    }

    /**
     * @return list<DesignLossCode>
     */
    private static function codes(DecompiledDesign $decompiled): array
    {
        /** @var array<string, DesignLossCode> $codes */
        $codes = [];

        foreach ($decompiled->losses as $loss) {
            $codes[$loss->code->value] = $loss->code;
        }

        ksort($codes);

        return array_values($codes);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function textbox(array $overrides): array
    {
        return $overrides + [
            'type' => 'Textbox',
            'left' => 0.0,
            'top' => 0.0,
            'width' => 400.0,
            'fontSize' => 24.0,
            'fontFamily' => 'Rubik (Rubik Bold)',
            'fill' => '#111111',
            'textAlign' => 'left',
            'lineHeight' => 1.16,
            'text' => 'Stand-in',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function image(array $overrides): array
    {
        return $overrides + [
            'type' => 'Image',
            'left' => 0.0,
            'top' => 0.0,
            'width' => 400.0,
            'height' => 200.0,
            'scaleX' => 1.0,
            'scaleY' => 1.0,
        ];
    }

    private static function textInput(string $inputId, null|string $name): EditorTextInput
    {
        return new EditorTextInput($inputId, $name, null, false, false, null, false);
    }

    private static function imageInput(string $inputId, null|string $name): EditorImageInput
    {
        return new EditorImageInput($inputId, $name, null, true, true, false, false, []);
    }
}
