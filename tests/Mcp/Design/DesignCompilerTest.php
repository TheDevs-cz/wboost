<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Design;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Exceptions\DesignCompilationFailed;
use WBoost\Web\Mcp\Design\CompilationContext;
use WBoost\Web\Mcp\Design\CompileErrorCode;
use WBoost\Web\Mcp\Design\CompiledDesign;
use WBoost\Web\Mcp\Design\DesignAsset;
use WBoost\Web\Mcp\Design\DesignCompiler;
use WBoost\Web\Mcp\Design\DesignIdentity;
use WBoost\Web\Mcp\Design\Dsl\BackgroundElement;
use WBoost\Web\Mcp\Design\Dsl\CanvasSpec;
use WBoost\Web\Mcp\Design\Dsl\ContainerElement;
use WBoost\Web\Mcp\Design\Dsl\DesignDocument;
use WBoost\Web\Mcp\Design\Dsl\DesignElement;
use WBoost\Web\Mcp\Design\Dsl\DslParser;
use WBoost\Web\Mcp\Design\Dsl\ImageElement;
use WBoost\Web\Mcp\Design\Dsl\ImageInputSpec;
use WBoost\Web\Mcp\Design\Dsl\Placement;
use WBoost\Web\Mcp\Design\Dsl\TextAlign;
use WBoost\Web\Mcp\Design\Dsl\TextElement;
use WBoost\Web\Mcp\Design\Dsl\TextInputSpec;
use WBoost\Web\Services\Editor\BackgroundLayer;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\CanvasShapeKind;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;

/**
 * {@see DesignCompiler} against plan §4 — **one test per numbered invariant**,
 * named `testInvariant<N>…` so coverage can be checked by eye rather than by
 * argument. §4 opens with *"a compiler that violates any of them produces a
 * variant that renders wrong, exports wrong, or breaks the fill page"*, and
 * nothing downstream would notice: there is no exception to catch, only a PNG
 * with the wrong words in the wrong boxes.
 *
 * Three of the tests below use production code as the oracle rather than a
 * restatement of the compiler's own logic, which is the only way an invariant
 * test earns its keep:
 *
 * - §4.1-1 runs the real {@see TextInputObjectBinder} over compiled output, so
 *   the binding asserted is the one the renderer and the fill page perform;
 * - §4.2-7 PARSES `assets/controllers/canvas_custom_properties.js` at test time,
 *   so the JS stays the source of truth it is declared to be (risk R7);
 * - §4.3-12 compares the emitted background against a direct
 *   {@see BackgroundLayer::buildObject()} call, so a hand-rolled cover
 *   transform cannot creep in.
 *
 * No kernel: {@see DesignCompiler} is a pure function of its arguments by
 * design (see {@see CompilationContext}), and the project context it needs
 * arrives as data.
 *
 * **Some fixtures build {@see DesignDocument} VOs directly instead of going
 * through {@see DslParser}.** That is deliberate and marked where it happens:
 * the parser already refuses cycles, one-member containers and placeholder
 * members, so a parsed document could never exercise the compiler's own
 * sanitizer — which still has to work, because the decompiler (S4-T5) builds
 * VOs directly too.
 */
final class DesignCompilerTest extends TestCase
{
    private const string FONT = 'Rubik (Rubik Regular)';
    private const string FONT_BOLD = 'Rubik (Rubik Bold)';

    /** A real 800×600 raster in the project's gallery. */
    private const string ASSET_PHOTO = '11111111-1111-4111-8111-111111111111';

    /** An SVG: in the gallery, but with no natural raster size. */
    private const string ASSET_LOGO = '22222222-2222-4222-8222-222222222222';

    /** A 1620×1080 raster, wider than a square canvas — the cover-crop case. */
    private const string ASSET_BACKGROUND = '33333333-3333-4333-8333-333333333333';

    /** Well-formed, and in nobody's gallery. */
    private const string ASSET_MISSING = '44444444-4444-4444-8444-444444444444';

    private const int CANVAS = 1080;

    /**
     * Fabric's own object properties, as opposed to the wboost custom ones.
     * Written out here rather than derived, so that adding a key to the
     * compiler's output is a conscious act reviewed against
     * {@see DesignCompiler::CANVAS_CUSTOM_PROPERTIES} (§4.2-7).
     *
     * @var list<string>
     */
    private const array FABRIC_PROPERTIES = [
        'type', 'version', 'originX', 'originY', 'left', 'top', 'width', 'height',
        'scaleX', 'scaleY', 'angle', 'cropX', 'cropY',
        'text', 'fontFamily', 'fontSize', 'fill', 'textAlign', 'lineHeight', 'charSpacing', 'editable',
        'src', 'crossOrigin',
        // Shape painting + per-type geometry. All native Fabric keys, which is
        // exactly why a shape needs no custom property for any of them.
        'opacity', 'stroke', 'strokeWidth', 'strokeUniform', 'strokeDashArray', 'strokeLineCap',
        'rx', 'ry', 'radius', 'points',
    ];

    // =================================================================
    // §4.1 — binding & identity
    // =================================================================

    /**
     * §4.1-1, the invariant risk R6 calls *"the most dangerous"*: the i-th
     * VISIBLE Textbox in `canvas.objects[]` is `inputs[i]`.
     *
     * The fixture interleaves images between texts and puts a background layer
     * at the bottom on purpose — a compiler that built `inputs[]` from a
     * different walk than `objects[]` would still pass on a text-only design,
     * and would mis-bind every real one.
     *
     * The oracle is {@see TextInputObjectBinder}, the production binder the
     * renderer stamps ids with and the fill overlay draws boxes from.
     */
    public function testInvariant1PositionalTextboxInputContract(): void
    {
        $compiled = $this->compile($this->interleavedDesign());

        $textboxInputIds = [];

        foreach ($compiled->objects() as $object) {
            if ($object['type'] === 'Textbox') {
                self::assertIsString($object['inputId']);
                $textboxInputIds[] = $object['inputId'];
            }
        }

        $inputIds = array_map(
            static fn (EditorTextInput $input): string => $input->inputId,
            $compiled->textInputs,
        );

        self::assertSame(['headline', 'subhead', 'legal'], $this->textSlugs($this->interleavedDesign()));
        self::assertSame($textboxInputIds, $inputIds, 'inputs[] order must equal the visible-Textbox order');

        // …and the production binder agrees, object index by object index.
        $binder = new TextInputObjectBinder(new CanvasPlaceholderGeometry());
        $bound = $binder->inputIdByObjectIndex($compiled->canvas, $compiled->textInputs);

        self::assertCount(3, $bound);

        foreach ($bound as $objectIndex => $inputId) {
            $object = $compiled->objects()[$objectIndex];
            self::assertSame('Textbox', $object['type']);
            self::assertSame($object['inputId'], $inputId, 'the binder must reach each textbox its OWN input');
        }
    }

    /**
     * §4.1-2: the `inputId` is stamped on the canvas object AND mirrored on the
     * input entry — and it is a UUID **v4** (`ProvideIdentity::next()`'s v7 is
     * for entity ids; every `inputId` in production is v4).
     */
    public function testInvariant2InputIdIsMirroredOnObjectAndInputAndIsUuidV4(): void
    {
        $compiled = $this->compile($this->interleavedDesign());

        $seen = [];

        foreach ($compiled->textInputs as $input) {
            self::assertSame(4, Uuid::fromString($input->inputId)->getVersion());
            $seen[$input->inputId] = true;
        }

        foreach ($compiled->imageInputs as $input) {
            self::assertSame(4, Uuid::fromString($input->inputId)->getVersion());
            $seen[$input->inputId] = true;
        }

        // Every input's id is carried by exactly one object, and every object
        // carries an id (decorative images included — the editor stamps one
        // proactively so a picture can be promoted to a placeholder by id).
        $objectIds = [];

        foreach ($compiled->objects() as $object) {
            self::assertArrayHasKey('inputId', $object);
            self::assertIsString($object['inputId']);
            self::assertSame(4, Uuid::fromString($object['inputId'])->getVersion());
            $objectIds[$object['inputId']] = true;
        }

        self::assertCount(count($compiled->objects()), $objectIds, 'ids must be unique across objects');

        foreach (array_keys($seen) as $inputId) {
            self::assertArrayHasKey($inputId, $objectIds);
        }
    }

    /**
     * §4.1-3: `visible: false` objects are excluded from both input arrays.
     *
     * The DSL has no vocabulary for a design-hidden layer — `visible` is in no
     * key set — so the invariant is satisfied STRUCTURALLY: the compiler emits
     * no hidden object, therefore every emitted textbox is an input and every
     * emitted placeholder is an image input. This test pins both halves, so
     * that a future DSL gaining a `visible` key cannot quietly start emitting
     * hidden objects while still counting them as fillable.
     */
    public function testInvariant3HiddenObjectsAreExcludedFromBothInputArrays(): void
    {
        foreach ([DslParser::TEXT_KEYS, DslParser::IMAGE_KEYS, DslParser::BACKGROUND_KEYS] as $keys) {
            self::assertNotContains('visible', $keys, 'DSL v1 cannot author a hidden element');
        }

        $compiled = $this->compile($this->interleavedDesign());

        $textboxes = 0;
        $placeholders = 0;

        foreach ($compiled->objects() as $object) {
            self::assertArrayNotHasKey('visible', $object, 'the compiler never emits a hidden object');

            if ($object['type'] === 'Textbox') {
                $textboxes++;
            }

            if ($object['type'] === 'Image' && ($object['imagePlaceholder'] ?? false) === true) {
                $placeholders++;
            }
        }

        self::assertCount($textboxes, $compiled->textInputs);
        self::assertCount($placeholders, $compiled->imageInputs);
    }

    /**
     * §4.1-4: `imageInputs[]` holds only images marked `imagePlaceholder: true`,
     * and they bind by their OWN `inputId` — image objects carry a reliable one,
     * so there is no positional contract here.
     */
    public function testInvariant4ImageInputsHoldOnlyPlaceholdersKeyedByTheirOwnInputId(): void
    {
        $compiled = $this->compile($this->interleavedDesign());

        $placeholderIds = [];
        $decorativeIds = [];

        foreach ($compiled->objects() as $object) {
            if ($object['type'] !== 'Image') {
                continue;
            }

            self::assertIsString($object['inputId']);

            if (($object['imagePlaceholder'] ?? false) === true) {
                $placeholderIds[] = $object['inputId'];
            } else {
                $decorativeIds[] = $object['inputId'];
            }
        }

        $inputIds = array_map(
            static fn (EditorImageInput $input): string => $input->inputId,
            $compiled->imageInputs,
        );

        self::assertSame($placeholderIds, $inputIds);
        self::assertNotSame([], $decorativeIds, 'the fixture must contain a decorative image');

        foreach ($decorativeIds as $decorativeId) {
            self::assertNotContains($decorativeId, $inputIds, 'a decorative image is never fillable');
        }

        // The `photo` slot's limits survive onto both faces.
        $photo = $compiled->imageInputs[0];
        self::assertSame('Foto', $photo->name);
        self::assertTrue($photo->allowMove);
        self::assertFalse($photo->allowRotate);
        self::assertSame(['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'], $photo->allowedDirectoryIds);
    }

    // =================================================================
    // §4.2 — Fabric object shape
    // =================================================================

    /**
     * §4.2-5: Fabric v7 defaults both origins to `center`. Every canvas in the
     * database, the renderer and `frameFromObject()` assume top-left, so an
     * object without explicit origins lands half a box off.
     *
     * @param array<string, mixed> $design
     */
    #[DataProvider('designProvider')]
    public function testInvariant5EveryObjectPinsTopLeftOrigins(array $design): void
    {
        foreach ($this->compile($design)->objects() as $object) {
            self::assertSame('left', $object['originX']);
            self::assertSame('top', $object['originY']);
        }
    }

    /**
     * §4.2-6: a textbox carries the WRAP width and never a height — Fabric
     * computes the height from the wrapped content, and an authored one
     * desynchronises container reflow (which measures the real height) from the
     * design. `GridResolver` always offers a band height; dropping it for
     * textboxes is explicitly the compiler's job.
     */
    public function testInvariant6TextboxesCarryAWrapWidthAndNeverAHeight(): void
    {
        // `at: {area: 'top'}` — the grid DOES hand the compiler a 360 px band
        // height here, which is exactly what must not survive onto the object.
        $compiled = $this->compile([
            'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS],
            'elements' => [
                [
                    'kind' => 'text', 'id' => 'headline', 'text' => 'SLEVA', 'font' => self::FONT, 'size' => 96,
                    'at' => ['area' => 'top', 'col' => [1, 12]],
                ],
            ],
        ]);

        $object = $compiled->objects()[0];

        self::assertSame('Textbox', $object['type']);
        self::assertArrayNotHasKey('height', $object);
        self::assertSame(1080.0, $object['width']);
        self::assertSame(0.0, $object['top']);
    }

    /**
     * §4.2-7 / risk R7: the compiler's custom-property list must agree with
     * `assets/controllers/canvas_custom_properties.js`, which is the source of
     * truth. The JS file is PARSED here rather than restated, so a property
     * added to the editor fails this test instead of being silently dropped
     * from designer metadata on the next save.
     */
    public function testInvariant7CustomPropertyListMirrorsTheJavaScriptSourceOfTruth(): void
    {
        $javascript = self::javaScriptCustomProperties();

        self::assertNotEmpty($javascript, 'the JS list must be parseable — check the regex, not the assertion');
        self::assertSame(
            $javascript,
            DesignCompiler::CANVAS_CUSTOM_PROPERTIES,
            'DesignCompiler::CANVAS_CUSTOM_PROPERTIES has drifted from canvas_custom_properties.js',
        );

        // The subsets the compiler actually writes must be members of it.
        foreach ([DesignCompiler::TEXT_CUSTOM_PROPERTIES, DesignCompiler::IMAGE_CUSTOM_PROPERTIES, DesignCompiler::SHAPE_CUSTOM_PROPERTIES] as $subset) {
            self::assertSame([], array_diff($subset, DesignCompiler::CANVAS_CUSTOM_PROPERTIES));
        }
    }

    /**
     * §4.2-7, the other half: nothing the compiler emits is a property that is
     * neither a Fabric one nor a declared custom one. A stray key would ride
     * into the canvas JSONB and out again as noise nobody restores.
     *
     * @param array<string, mixed> $design
     */
    #[DataProvider('designProvider')]
    public function testInvariant7CompiledObjectsCarryNoUndeclaredProperty(array $design): void
    {
        $allowed = array_merge(self::FABRIC_PROPERTIES, DesignCompiler::CANVAS_CUSTOM_PROPERTIES);

        foreach ($this->compile($design)->objects() as $object) {
            self::assertSame(
                [],
                array_values(array_diff(array_keys($object), $allowed)),
                'undeclared canvas property emitted',
            );
        }
    }

    /**
     * §4.2-8: Fabric does NOT serialize its interaction flags — the editor
     * re-derives them on load (`applyTextboxDefaults` / `applyEditorLock`), so
     * authoring them writes state nobody reads.
     *
     * `editorLocked` is treated apart from the rest, and §4.2-8 is imprecise on
     * this point: unlike the others it IS a persisted custom property and it is
     * the INPUT to `applyEditorLock`, not its output.
     * {@see BackgroundLayer::buildObject()} seeds it `true` — a full-canvas
     * image must be click-through or it swallows every rubber-band — and
     * §4.3-12 requires using that builder, so stripping it back off would be
     * precisely the hand-rolling §4.3-12 forbids. It therefore appears on the
     * background layer and on SHAPES, whose `locked` key words the very same
     * flag, and nowhere else.
     *
     * @param array<string, mixed> $design
     */
    #[DataProvider('designProvider')]
    public function testInvariant8EditorOnlyInteractionFlagsAreNeverAuthored(array $design): void
    {
        $flags = [
            'lockScalingX', 'lockScalingY', 'lockScalingFlip', 'lockRotation',
            'lockMovementX', 'lockMovementY', 'hasControls', 'selectable', 'evented',
            'cornerStyle', 'cornerSize', 'hoverCursor',
        ];

        foreach ($this->compile($design)->objects() as $object) {
            foreach ($flags as $flag) {
                self::assertArrayNotHasKey($flag, $object);
            }

            if (($object['isBackground'] ?? false) !== true && ($object['shapeKind'] ?? null) === null) {
                self::assertArrayNotHasKey('editorLocked', $object, 'only the background layer and shapes carry the editor lock');
            }
        }
    }

    // =================================================================
    // shapes
    // =================================================================

    /**
     * Each shape kind compiles to the Fabric type the EDITOR would have made
     * for it (`canvas_shapes.js` `createShapeObject`), carrying that type's own
     * geometry model. Getting this wrong does not fail anywhere: the canvas
     * saves, and the export simply draws a different shape.
     */
    public function testEveryShapeKindCompilesToItsEditorFabricType(): void
    {
        $shapes = $this->compiledShapes();

        self::assertSame('Rect', $shapes['rectangle']['type']);
        self::assertSame('Rect', $shapes['square']['type']);
        self::assertSame('Rect', $shapes['line']['type'], 'A divider is a thin Rect, never a Fabric Line.');
        self::assertSame('Circle', $shapes['circle']['type']);
        self::assertSame('Ellipse', $shapes['ellipse']['type']);
        self::assertSame('Triangle', $shapes['triangle']['type']);
        self::assertSame('Polygon', $shapes['star']['type']);

        // Per-type geometry models, all hitting the same authored 300 x 120 box.
        self::assertSame(12.0, $shapes['rectangle']['rx']);
        self::assertSame(150.0, $shapes['circle']['radius']);
        self::assertSame(0.4, $shapes['circle']['scaleY'], 'A Circle has one radius, so a non-square box needs a scale.');
        self::assertSame(150.0, $shapes['ellipse']['rx']);
        self::assertSame(60.0, $shapes['ellipse']['ry']);

        $points = $shapes['star']['points'];
        self::assertIsArray($points);
        self::assertCount(10, $points);
    }

    /**
     * The star's points must span the authored box EXACTLY. That is what makes
     * the round-trip stable: an editor-made star already fills its own bbox, so
     * decompiling it to that box and regenerating here has to reproduce it.
     */
    public function testAStarPolygonSpansExactlyItsAuthoredBox(): void
    {
        $points = $this->compiledShapes()['star']['points'];
        self::assertIsArray($points);

        $xs = [];
        $ys = [];

        foreach ($points as $point) {
            self::assertIsArray($point);
            self::assertIsFloat($point['x']);
            self::assertIsFloat($point['y']);
            $xs[] = $point['x'];
            $ys[] = $point['y'];
        }

        self::assertNotSame([], $xs);
        self::assertSame(0.0, min($xs));
        self::assertSame(300.0, max($xs));
        self::assertSame(0.0, min($ys));
        self::assertSame(120.0, max($ys));
    }

    /**
     * A gradient fill compiles to PERCENTAGE units — coordinates as a fraction
     * of the object's own box. In pixel units it would be baked to the size the
     * shape happened to be compiled at and would slide off under the designer's
     * resize, the group projector and the print-resolution export alike.
     */
    public function testAGradientFillCompilesInPercentageUnits(): void
    {
        $named = $this->compiledShapesByName();

        $linear = $named['Panel']['fill'];
        self::assertIsArray($linear);
        self::assertSame('linear', $linear['type']);
        self::assertSame('percentage', $linear['gradientUnits']);

        $stops = $linear['colorStops'];
        self::assertIsArray($stops);
        self::assertSame(['#ff0000', '#0000ff'], array_column($stops, 'color'));

        $coords = $linear['coords'];
        self::assertIsArray($coords);
        // 45 degrees: both axes travel the same distance from the centre.
        self::assertEqualsWithDelta(0.5 - cos(M_PI / 4) / 2, $coords['x1'], 1.0e-9);
        self::assertEqualsWithDelta(0.5 + sin(M_PI / 4) / 2, $coords['y2'], 1.0e-9);

        $radial = $named['Glow']['fill'];
        self::assertIsArray($radial);
        self::assertSame('radial', $radial['type']);
        self::assertSame('percentage', $radial['gradientUnits']);

        $radialCoords = $radial['coords'];
        self::assertIsArray($radialCoords);
        self::assertSame(0.5, $radialCoords['r2']);
    }

    public function testADottedStrokeScalesItsDashPatternWithTheStrokeWidth(): void
    {
        // The gradient panel is the second `rectangle`; compiledShapes() keeps
        // the first, so find it by its layer name.
        foreach ($this->compile(self::shapeDesign())->objects() as $object) {
            if (($object['name'] ?? null) !== 'Panel') {
                continue;
            }

            // 'dotted' at width 6: zero-length dashes, round caps.
            self::assertSame([0.0, 12.0], $object['strokeDashArray']);
            self::assertSame('round', $object['strokeLineCap']);
            self::assertTrue($object['strokeUniform']);
            self::assertSame(0.5, $object['opacity']);
            self::assertTrue($object['editorLocked']);

            return;
        }

        self::fail('The gradient panel was not compiled.');
    }

    /**
     * The compiled shape objects that carry a layer name, keyed by it — the way
     * to reach a SPECIFIC one where the kind is ambiguous (the fixture has two
     * rectangles and two ellipses).
     *
     * @return array<string, array<string, mixed>>
     */
    private function compiledShapesByName(): array
    {
        $shapes = [];

        foreach ($this->compile(self::shapeDesign())->objects() as $object) {
            $name = $object['name'] ?? null;

            if (isset($object['shapeKind']) && is_string($name)) {
                $shapes[$name] = $object;
            }
        }

        return $shapes;
    }

    /**
     * The compiled shape objects, keyed by `shapeKind` — first occurrence wins.
     *
     * @return array<string, array<string, mixed>>
     */
    private function compiledShapes(): array
    {
        $shapes = [];

        foreach ($this->compile(self::shapeDesign())->objects() as $object) {
            $kind = $object['shapeKind'] ?? null;

            if (is_string($kind) && !isset($shapes[$kind])) {
                $shapes[$kind] = $object;
            }
        }

        return $shapes;
    }

    /**
     * Shapes are decorative: they must never reach the input DTOs, or a
     * `describe_variant` would offer the user a rectangle to type into and the
     * positional textbox contract would shift under every text after it.
     */
    public function testShapesNeverBecomeTextOrImageInputs(): void
    {
        $compiled = $this->compile(self::shapeDesign());

        self::assertCount(1, $compiled->textInputs, 'only the one text element');
        self::assertSame([], $compiled->imageInputs);

        // They still carry an inputId — the container/group join key.
        foreach ($compiled->objects() as $object) {
            if (isset($object['shapeKind'])) {
                self::assertNotNull($object['inputId'] ?? null);
            }
        }
    }

    /**
     * §4.2-9: an image needs `src` (the public URL) AND `assetPath` — without
     * the latter `AssetInliner` cannot inline it and Gotenberg's Chromium, which
     * has no route to Minio, paints nothing. `assetId` rides along where known.
     *
     * An image with no picture at all still needs a loadable `src`: an empty or
     * unreachable one makes `loadFromJSON` never settle and the render hangs
     * until the Gotenberg timeout, so it gets the same transparent-pixel stub
     * the renderer's slicer uses.
     */
    public function testInvariant9ImageObjectsCarrySrcAssetPathAndAssetId(): void
    {
        $compiled = $this->compile($this->interleavedDesign());

        $withAsset = 0;

        foreach ($compiled->objects() as $object) {
            if ($object['type'] !== 'Image') {
                continue;
            }

            self::assertIsString($object['src']);
            self::assertNotSame('', $object['src']);

            if (str_starts_with($object['src'], 'data:')) {
                continue;
            }

            $withAsset++;
            self::assertArrayHasKey('assetPath', $object);
            self::assertArrayHasKey('assetId', $object);
            self::assertIsString($object['assetPath']);
            self::assertSame('https://cdn.example.test/' . $object['assetPath'], $object['src']);
            self::assertSame('anonymous', $object['crossOrigin']);
        }

        self::assertSame(3, $withAsset, 'background + photo + logo are all gallery-backed');

        // …and an image with no asset gets the stub rather than an empty src.
        $empty = $this->compile([
            'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS],
            'elements' => [
                ['kind' => 'image', 'id' => 'slot', 'x' => 0, 'y' => 0, 'width' => 400, 'height' => 400, 'input' => ['name' => 'Foto']],
            ],
        ])->objects()[0];

        self::assertIsString($empty['src']);
        self::assertStringStartsWith('data:image/png;base64,', $empty['src']);
        self::assertArrayNotHasKey('assetPath', $empty);
        self::assertNull($empty['crossOrigin']);
    }

    /**
     * §4.2-10: `fontFamily` must be an exact face string from the project.
     * Unknown → a hard error naming the allowed list, mirroring the export
     * API's `font_not_allowed` so the agent self-corrects in the same turn
     * rather than rendering in a substitute face nobody asked for.
     */
    public function testInvariant10UnknownFontIsAHardErrorNamingTheAllowedFaces(): void
    {
        try {
            $this->compile([
                'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS],
                'elements' => [
                    ['kind' => 'text', 'id' => 'headline', 'text' => 'Ahoj', 'font' => 'Helvetica', 'size' => 40, 'x' => 0, 'y' => 0, 'width' => 500],
                ],
            ]);
            self::fail('an unknown font must not compile');
        } catch (DesignCompilationFailed $exception) {
            self::assertCount(1, $exception->violations);

            $violation = $exception->violations[0];
            self::assertSame('elements[0].font', $violation->path);
            self::assertSame(CompileErrorCode::FontNotAllowed, $violation->code);
            self::assertSame([self::FONT, self::FONT_BOLD], $violation->allowed);
            self::assertStringContainsString('"Helvetica"', $violation->message);
            self::assertStringContainsString(self::FONT_BOLD, $violation->message);
            self::assertStringContainsString('"Helvetica"', $exception->getMessage());
        }
    }

    // =================================================================
    // §4.3 — background
    // =================================================================

    /**
     * §4.3-11: at most ONE `isBackground` object, and it sits at stack index 0
     * — wherever the author put the element in `elements[]`, because the stack
     * position of a background is not theirs to choose.
     */
    public function testInvariant11ExactlyOneBackgroundObjectAtStackIndexZero(): void
    {
        // The background element is declared LAST here, on purpose.
        $compiled = $this->compile([
            'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS],
            'elements' => [
                ['kind' => 'text', 'id' => 'headline', 'text' => 'Ahoj', 'font' => self::FONT, 'size' => 40, 'x' => 0, 'y' => 0, 'width' => 500],
                ['kind' => 'background', 'id' => 'bg', 'asset' => self::ASSET_BACKGROUND],
            ],
        ]);

        $backgrounds = array_values(array_filter(
            $compiled->objects(),
            static fn (array $object): bool => ($object['isBackground'] ?? false) === true,
        ));

        self::assertCount(1, $backgrounds);
        self::assertTrue($compiled->objects()[0]['isBackground']);
        self::assertSame('Textbox', $compiled->objects()[1]['type']);
    }

    /**
     * §4.3-12: the cover transform is {@see BackgroundLayer::buildObject()}'s —
     * least scale that covers, anchored top-left, overflow cropped bottom-right
     * — shared with `coverForDimensions()` in the editor and
     * `ImagePlacement::computeCover` on the server. A second copy would drift
     * from the editor by a pixel and from the group projector by a crop, so the
     * assertion is against the builder itself, not against numbers restated
     * here.
     */
    public function testInvariant12BackgroundIsBuiltByTheSharedCoverTransform(): void
    {
        $compiled = $this->compile($this->interleavedDesign());
        $background = $compiled->objects()[0];

        self::assertIsString($background['inputId']);

        $expected = (new BackgroundLayer())->buildObject(
            'https://cdn.example.test/project-image/bg.jpg',
            'project-image/bg.jpg',
            1620,
            1080,
            (float) self::CANVAS,
            (float) self::CANVAS,
            $background['inputId'],
        );
        // `assetId` is the compiler's addition (§4.2-9) — the builder's other
        // callers write a freshly uploaded file, not a gallery row.
        $expected['assetId'] = self::ASSET_BACKGROUND;

        self::assertSame($expected, $background);
        // The 1620-wide picture covers a 1080 square by height: 1080/1080 = 1.
        self::assertSame(1.0, $background['scaleX']);
        self::assertSame(0, $background['left']);
        self::assertSame(0, $background['top']);
    }

    /**
     * §4.3-13: `template_variant.background_image` is the denormalized pointer
     * to the layer's `assetPath`. `EditTemplateVariantCanvasHandler` performs
     * that sync — the compiler's job is to make the value available and to make
     * the two agree, which is what is asserted here.
     */
    public function testInvariant13BackgroundAssetPathMatchesTheLayerForDenormalization(): void
    {
        $compiled = $this->compile($this->interleavedDesign());

        self::assertSame('project-image/bg.jpg', $compiled->backgroundAssetPath);
        self::assertSame($compiled->objects()[0]['assetPath'], $compiled->backgroundAssetPath);
        self::assertSame(
            $compiled->backgroundAssetPath,
            (new BackgroundLayer())->extractAssetPath($compiled->canvasJson()),
            'the handler recovers the pointer from the canvas through this exact call',
        );
    }

    /**
     * §4.3-14: a layer-mode variant with no background renders a TRANSPARENT
     * PNG. That is legal, not an error — no object, no violation, and nothing
     * for the handler to denormalize.
     *
     * The one refusal is `fillable: true` with no asset: the Phase-B contract
     * is that an unfilled background slot renders the DESIGNED picture, so a
     * fillable background without one promises a stand-in that does not exist,
     * and compiling it would silently drop the flag.
     */
    public function testInvariant14ADesignWithoutABackgroundIsLegal(): void
    {
        $compiled = $this->compile([
            'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS],
            'elements' => [
                ['kind' => 'background', 'id' => 'bg', 'asset' => null],
                ['kind' => 'text', 'id' => 'headline', 'text' => 'Ahoj', 'font' => self::FONT, 'size' => 40, 'x' => 0, 'y' => 0, 'width' => 500],
            ],
        ]);

        self::assertNull($compiled->backgroundAssetPath);
        self::assertCount(1, $compiled->objects());
        self::assertSame('Textbox', $compiled->objects()[0]['type']);

        $this->expectException(DesignCompilationFailed::class);
        $this->expectExceptionMessage('a fillable background needs an "asset"');

        $this->compile([
            'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS],
            'elements' => [
                ['kind' => 'background', 'id' => 'bg', 'asset' => null, 'fillable' => true],
            ],
        ]);
    }

    // =================================================================
    // §4.4 — containers
    // =================================================================

    /**
     * §4.4-15: containers live as a top-level `containers` key INSIDE the canvas
     * JSON (no column, no migration), shaped
     * `{id, maxHeight, memberInputIds, memberContainerIds, gap?, spaceAfter?}`.
     */
    public function testInvariant15ContainersLiveInsideTheCanvasJsonWithTheDocumentedShape(): void
    {
        $compiled = $this->compile($this->containerDesign());

        self::assertCount(1, $compiled->canvas['containers']);

        $container = $compiled->canvas['containers'][0];

        self::assertSame(
            ['id', 'maxHeight', 'memberInputIds', 'memberContainerIds', 'gap', 'spaceAfter'],
            array_keys($container),
        );
        // The DSL slug IS the canvas id: stable across set_design, so
        // `memberContainerIds` and the strict-export 400's `containerId` keep
        // pointing at the same thing after an edit.
        self::assertSame('body', $container['id']);
        self::assertSame(400.0, $container['maxHeight']);
        self::assertSame(24.0, $container['gap'] ?? null);
        self::assertSame(60.0, $container['spaceAfter'] ?? null);

        // Round-trips through the canvas string. `assertEquals`, not
        // `assertSame`: JSON has one number type, so a whole float comes back an
        // int — which `CanvasContainer::fromArray()` accepts and casts, and
        // which the editor's own saves have always looked like.
        $decoded = json_decode($compiled->canvasJson(), true);
        self::assertIsArray($decoded);
        self::assertEquals($compiled->canvas['containers'], $decoded['containers']);

        // gap / spaceAfter are ABSENT (not null) when the design leaves them
        // out, mirroring sanitizedContainers()'s conditional assignment.
        $bare = $this->compile($this->containerDesign(gap: null, spaceAfter: null));
        self::assertSame(
            ['id', 'maxHeight', 'memberInputIds', 'memberContainerIds'],
            array_keys($bare->canvas['containers'][0]),
        );
    }

    /**
     * §4.4-16: `memberInputIds` is in FLOW order — ascending designed `top`,
     * re-derived by the compiler, never trusted from the document. An author who
     * drags an element upwards must not also have to remember to reorder a list.
     *
     * The fixture lists its members bottom-up, so a compiler that trusted the
     * DSL order would reflow the container upside down.
     */
    public function testInvariant16MemberInputIdsAreReDerivedInAscendingDesignedTop(): void
    {
        $compiled = $this->compile($this->containerDesign());

        /** @var array<string, float> $topBySlug */
        $topBySlug = [];

        foreach ($compiled->objects() as $object) {
            self::assertIsString($object['inputId']);
            self::assertIsFloat($object['top']);
            $topBySlug[$object['inputId']] = $object['top'];
        }

        $tops = array_map(
            static fn (string $inputId): float => $topBySlug[$inputId],
            $compiled->canvas['containers'][0]['memberInputIds'],
        );

        self::assertSame([120.0, 260.0, 400.0], $tops);

        // The document listed them the other way round — proving the order was
        // computed, not copied.
        $document = DslParser::parse($this->containerDesign());
        $container = $document->containerElements()[0];
        self::assertSame(['legal', 'subhead', 'headline'], $container->memberIds);
    }

    /**
     * §4.4-17: sanitization matches `sanitizedContainers()` in
     * `assets/controllers/canvas_payload.js`, **to a fixpoint** — dropping a
     * degenerate child can strip its parent below the two-item minimum, so a
     * single pass leaves inert definitions behind.
     *
     * Built as VOs rather than parsed: the parser refuses one-member containers
     * and cycles outright, so only a hand-built document can reach the
     * compiler's own sanitizer — which still has to work, because the
     * decompiler (S4-T5) builds VOs directly.
     */
    public function testInvariant17SanitizationDropsDegenerateContainersToAFixpoint(): void
    {
        $document = new DesignDocument(
            new CanvasSpec(self::CANVAS, self::CANVAS),
            [
                self::text('headline', 100.0),
                self::text('subhead', 200.0),
                self::text('orphan', 300.0),
                // `inner` counts one real member + one member that does not
                // exist → 1 item → dropped. `outer` counted `inner` as its
                // second item → 1 item → must be dropped in the NEXT pass.
                new ContainerElement('inner', ['orphan', 'ghost'], [], 100.0, null, null),
                new ContainerElement('outer', ['headline'], ['inner'], 400.0, null, null),
                // Survives: two real members.
                new ContainerElement('keep', ['headline', 'subhead'], [], 300.0, null, null),
                // Dropped by CanvasContainer's own rule too (maxHeight ≤ 0).
                new ContainerElement('unbounded', ['headline', 'subhead'], [], 0.0, null, null),
            ],
        );

        $containers = $this->compiler()->compile($document, $this->context(), DesignIdentity::fresh())
            ->canvas['containers'];

        self::assertSame(['keep'], array_column($containers, 'id'));

        // Cycles: a child reference reaching back to an ancestor is dropped, so
        // the forest stays a tree and the layout engine terminates. The graph is
        // updated as it is walked (as the JS mutates its container objects), so
        // a two-node cycle is broken ONCE — the first container gives up its
        // child, the second keeps its own.
        $cyclic = new DesignDocument(
            new CanvasSpec(self::CANVAS, self::CANVAS),
            [
                self::text('a', 100.0),
                self::text('b', 200.0),
                self::text('c', 300.0),
                self::text('d', 400.0),
                new ContainerElement('one', ['a', 'b'], ['two'], 400.0, null, null),
                new ContainerElement('two', ['c', 'd'], ['one'], 400.0, null, null),
            ],
        );

        $containers = $this->compiler()->compile($cyclic, $this->context(), DesignIdentity::fresh())
            ->canvas['containers'];

        self::assertSame([], $containers[0]['memberContainerIds'], 'the first container gives up the cyclic edge');
        self::assertSame(['one'], $containers[1]['memberContainerIds'], 'and the pair is a tree again');

        // One parent per child: a second claimant is refused even without a cycle.
        $twoParents = new DesignDocument(
            new CanvasSpec(self::CANVAS, self::CANVAS),
            [
                self::text('a', 100.0),
                self::text('b', 200.0),
                self::text('c', 300.0),
                self::text('d', 400.0),
                new ContainerElement('child', ['a', 'b'], [], 200.0, null, null),
                new ContainerElement('first', ['c'], ['child'], 400.0, null, null),
                new ContainerElement('second', ['d'], ['child'], 400.0, null, null),
            ],
        );

        $containers = $this->compiler()->compile($twoParents, $this->context(), DesignIdentity::fresh())
            ->canvas['containers'];

        self::assertSame(['child', 'first'], array_column($containers, 'id'));
        self::assertSame(['child'], $containers[1]['memberContainerIds']);
    }

    /**
     * §4.4-18: fillable image placeholders and the background layer are never
     * container members — their frames are load-bearing elsewhere (the fill
     * page's live objects, the clipPath rect, the API contract). Decorative
     * images may be, and are: a checklist icon riding along with its line is the
     * whole point of image members.
     *
     * Hand-built for the same reason as §4.4-17: the parser refuses these
     * members by slug, so only a VO document reaches the sanitizer's own guard
     * — which mirrors `isMemberCandidate()` in `assets/editor/container_layout.js`.
     */
    public function testInvariant18PlaceholdersAndTheBackgroundAreNeverContainerMembers(): void
    {
        $document = new DesignDocument(
            new CanvasSpec(self::CANVAS, self::CANVAS),
            [
                self::background('bg', self::ASSET_BACKGROUND),
                self::text('headline', 100.0),
                self::image('icon', 200.0, self::ASSET_LOGO, null),
                self::image('photo', 300.0, self::ASSET_PHOTO, new ImageInputSpec(name: 'Foto')),
                self::text('subhead', 400.0),
                new ContainerElement('body', ['headline', 'photo', 'icon', 'bg', 'subhead'], [], 600.0, null, null),
            ],
        );

        $compiled = $this->compiler()->compile($document, $this->context(), DesignIdentity::fresh());

        $slugOf = [];

        foreach (['bg', 'headline', 'icon', 'photo', 'subhead'] as $index => $slug) {
            self::assertIsString($compiled->objects()[$index]['inputId']);
            $slugOf[$compiled->objects()[$index]['inputId']] = $slug;
        }

        $members = array_map(
            static fn (string $inputId): string => $slugOf[$inputId],
            $compiled->canvas['containers'][0]['memberInputIds'],
        );

        self::assertSame(['headline', 'icon', 'subhead'], $members);
    }

    /**
     * §4.4-19: {@see CanvasContainer} parses defensively and must never throw on
     * compiler output — and, the stronger property, must never DROP any of it.
     * A definition the compiler emitted but the VO discards is a container the
     * agent was shown and the render does not have.
     *
     * @param array<string, mixed> $design
     */
    #[DataProvider('designProvider')]
    public function testInvariant19CanvasContainerAcceptsEveryCompilerOutput(array $design): void
    {
        $compiled = $this->compile($design);
        $parsed = CanvasContainer::collectionFromCanvas($compiled->canvas);

        self::assertCount(count($compiled->canvas['containers']), $parsed);

        foreach ($parsed as $index => $container) {
            $emitted = $compiled->canvas['containers'][$index];

            self::assertSame($emitted['id'], $container->id);
            self::assertSame($emitted['maxHeight'], $container->maxHeight);
            self::assertSame($emitted['memberInputIds'], $container->memberInputIds);
            self::assertSame($emitted['memberContainerIds'], $container->memberContainerIds);
            self::assertSame($emitted['gap'] ?? null, $container->gap);
            self::assertSame($emitted['spaceAfter'] ?? null, $container->spaceAfter);
        }

        // …and it survives the JSONB round trip the column performs.
        $decoded = json_decode($compiled->canvasJson(), true);
        self::assertIsArray($decoded);
        self::assertCount(count($parsed), CanvasContainer::collectionFromCanvas($decoded));
    }

    /**
     * §4.4, the nesting asymmetry: only a ROOT's `maxHeight` bounds the flow, so
     * the DSL lets a nested container omit it — but
     * {@see CanvasContainer::fromArray()} drops a non-positive one, so the
     * compiler must synthesize an inert POSITIVE value rather than skip the key.
     */
    public function testNestedContainerWithoutAMaxHeightGetsAnInertPositiveBound(): void
    {
        $compiled = $this->compile([
            'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS],
            'elements' => [
                ['kind' => 'text', 'id' => 'a', 'text' => 'A', 'font' => self::FONT, 'size' => 40, 'x' => 0, 'y' => 100, 'width' => 500],
                ['kind' => 'text', 'id' => 'b', 'text' => 'B', 'font' => self::FONT, 'size' => 40, 'x' => 0, 'y' => 200, 'width' => 500],
                ['kind' => 'text', 'id' => 'c', 'text' => 'C', 'font' => self::FONT, 'size' => 40, 'x' => 0, 'y' => 300, 'width' => 500],
                ['kind' => 'container', 'id' => 'child', 'members' => ['a', 'b']],
                ['kind' => 'container', 'id' => 'root', 'members' => ['c'], 'children' => ['child'], 'maxHeight' => 500],
            ],
        ]);

        $byId = array_column($compiled->canvas['containers'], null, 'id');

        self::assertSame(1080.0, $byId['child']['maxHeight'], 'the canvas height, ignored for a nested container');
        self::assertSame(500.0, $byId['root']['maxHeight']);
        self::assertCount(2, CanvasContainer::collectionFromCanvas($compiled->canvas));
    }

    // =================================================================
    // §4.5 — persistence
    // =================================================================

    /**
     * §4.5-20: all canvas writes go through `EditTemplateVariantCanvasEditor`;
     * there is no direct `$variant->editCanvas()` from MCP code.
     *
     * The compiler cannot violate that by construction and this test is what
     * keeps it so: its only collaborator is {@see BackgroundLayer}, so no
     * entity manager, repository or message bus is reachable from it. A future
     * change that injects one fails here rather than in production, where a
     * compile with a side effect is invisible until `preview_design` starts
     * saving.
     */
    public function testInvariant20TheCompilerCannotPersistAnything(): void
    {
        $constructor = (new \ReflectionClass(DesignCompiler::class))->getConstructor();
        self::assertNotNull($constructor);

        $types = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor->getParameters(),
        );

        self::assertSame([BackgroundLayer::class], $types);

        // And `compile()` reads nothing but its arguments.
        $compile = new \ReflectionMethod(DesignCompiler::class, 'compile');
        self::assertSame(
            [DesignDocument::class, CompilationContext::class, DesignIdentity::class],
            array_map(
                static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
                $compile->getParameters(),
            ),
        );
    }

    /**
     * §4.5-21: `previewImageDataUri` is browser-produced and unavailable here.
     * The compiler therefore produces no preview at all — S5-T3 passes `''` to
     * the handler (which keeps the existing thumbnail) and renders one
     * server-side afterwards.
     *
     * Pinning {@see CompiledDesign}'s property set is what makes that contract
     * explicit: a caller cannot mistake something in here for a thumbnail, and
     * an added preview field would have to be argued for.
     */
    public function testInvariant21CompilerOutputCarriesNoPreviewImage(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass(CompiledDesign::class))->getProperties(),
        );

        self::assertSame(['canvas', 'textInputs', 'imageInputs', 'backgroundAssetPath'], $properties);
    }

    /**
     * §4.5-22: a group-created variant (`variant->group !== null`) must be
     * rejected by `set_design`, because the next group save would clobber it.
     *
     * **Not enforceable here, and deliberately so.** The compiler never sees a
     * `TemplateVariant` — it compiles a document against a project context, and
     * `preview_design` legitimately compiles designs for variants it will never
     * write. The check belongs at the write boundary (S5-T3), exactly as
     * `TemplateVariantEditorController` puts it at the edit boundary. This test
     * pins the reason: nothing in the compiler's signature could perform it.
     */
    public function testInvariant22GroupedVariantRejectionBelongsToTheWriteBoundary(): void
    {
        $compile = new \ReflectionMethod(DesignCompiler::class, 'compile');

        foreach ($compile->getParameters() as $parameter) {
            self::assertStringNotContainsString('TemplateVariant', (string) $parameter->getType());
        }

        // The entity is not even imported, so nothing in here could grow the
        // check by accident. (The class docblock names
        // `EditTemplateVariantCanvasEditor` as the write path, which is why this
        // asserts on the import rather than on the file's text.)
        self::assertStringNotContainsString(
            'use WBoost\Web\Entity\TemplateVariant;',
            (string) file_get_contents(__DIR__ . '/../../../src/Mcp/Design/DesignCompiler.php'),
        );
    }

    // =================================================================
    // identity, fits and the remaining compile-stage refusals
    // =================================================================

    /**
     * The property that makes editing safe (plan §3.4): a slug the variant
     * already knows keeps its `inputId`; a new slug mints a fresh one. Without
     * it every edit would hand the variant a new set of ids and silently break
     * every saved fill, every API consumer and every container membership.
     */
    public function testSlugsKeepTheirInputIdAcrossCompilesAndNewSlugsMint(): void
    {
        $first = $this->compile($this->interleavedDesign());

        $identity = DesignIdentity::fromMap([
            'headline' => $first->textInputs[0]->inputId,
            'subhead' => $first->textInputs[1]->inputId,
            'legal' => $first->textInputs[2]->inputId,
            'photo' => $first->imageInputs[0]->inputId,
        ]);

        // Same slugs, different copy and a NEW element.
        $design = $this->interleavedDesign();
        $design['elements'][1]['text'] = 'ÚPLNĚ JINÝ NADPIS';
        $design['elements'][] = [
            'kind' => 'text', 'id' => 'badge', 'text' => 'NOVÉ', 'font' => self::FONT,
            'size' => 24, 'x' => 900, 'y' => 40, 'width' => 140,
        ];

        $second = $this->compiler()->compile(DslParser::parse($design), $this->context(), $identity);

        self::assertSame($first->textInputs[0]->inputId, $second->textInputs[0]->inputId);
        self::assertSame($first->textInputs[1]->inputId, $second->textInputs[1]->inputId);
        self::assertSame($first->textInputs[2]->inputId, $second->textInputs[2]->inputId);
        self::assertSame($first->imageInputs[0]->inputId, $second->imageInputs[0]->inputId);

        self::assertCount(4, $second->textInputs);
        self::assertNotContains(
            $second->textInputs[3]->inputId,
            array_map(static fn (EditorTextInput $input): string => $input->inputId, $first->textInputs),
        );
    }

    /**
     * A variant's persisted inputs name their slugs through the shared
     * {@see \WBoost\Web\Mcp\Design\DesignSlug} rule — the one the decompiler
     * (S4-T5) must also use, or the two halves of a `get_design` →
     * `set_design` round trip would disagree about what a slug means.
     */
    public function testIdentityFromPersistedInputsSlugifiesAndDedupesNames(): void
    {
        $identity = DesignIdentity::fromInputs(
            [
                new EditorTextInput('id-1', 'Nadpis', null, false, false, null, false),
                new EditorTextInput('id-2', 'Nadpis', null, false, false, null, false),
                new EditorTextInput('id-3', null, null, false, false, null, false),
                new EditorTextInput('id-4', 'Zaškrtávací seznam', null, false, false, null, false),
            ],
            [
                new EditorImageInput('id-5', 'Foto', null, true, true, false, false, []),
                new EditorImageInput('id-6', null, null, false, false, false, false, [], isBackground: true),
            ],
        );

        self::assertSame([
            'nadpis' => 'id-1',
            'nadpis-2' => 'id-2',
            'text' => 'id-3',
            'zaskrtavaci-seznam' => 'id-4',
            'foto' => 'id-5',
            'background' => 'id-6',
        ], $identity->toArray());
    }

    /**
     * The two image fits, and why they differ: a fillable placeholder's rect IS
     * the slot frame the API, the fill overlay and `ImagePlacement` all read, so
     * it is filled exactly; a decorative picture is contain-fitted and centred,
     * because stretching a logo to a grid cell is never what the author meant.
     */
    public function testPlaceholdersFillTheirFrameWhileDecorativeImagesAreContainFitted(): void
    {
        $compiled = $this->compile([
            'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS],
            'elements' => [
                // 800×600 picture into a 400×400 slot.
                ['kind' => 'image', 'id' => 'photo', 'asset' => self::ASSET_PHOTO, 'x' => 100, 'y' => 100, 'width' => 400, 'height' => 400, 'input' => ['name' => 'Foto']],
                ['kind' => 'image', 'id' => 'mark', 'asset' => self::ASSET_PHOTO, 'x' => 100, 'y' => 600, 'width' => 400, 'height' => 400],
            ],
        ]);

        [$placeholder, $decorative] = $compiled->objects();

        // Exact frame: 400/800 horizontally, 400/600 vertically.
        self::assertSame(800.0, $placeholder['width']);
        self::assertSame(0.5, $placeholder['scaleX']);
        self::assertEqualsWithDelta(400 / 600, $placeholder['scaleY'], 1e-9);
        self::assertSame(100.0, $placeholder['left']);
        self::assertSame(100.0, $placeholder['top']);

        // Contain: uniform 0.5, centred vertically inside the 400 px box.
        self::assertSame(0.5, $decorative['scaleX']);
        self::assertSame(0.5, $decorative['scaleY']);
        self::assertSame(100.0, $decorative['left']);
        self::assertSame(650.0, $decorative['top']);

        // A picture with no natural size (an SVG) falls back to the rect itself
        // at scale 1 — the same fallback BackgroundLayer takes, for the same
        // reason: there is no ratio to preserve.
        $svg = $this->compile([
            'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS],
            'elements' => [
                ['kind' => 'image', 'id' => 'logo', 'asset' => self::ASSET_LOGO, 'x' => 10, 'y' => 20, 'width' => 300, 'height' => 150],
            ],
        ])->objects()[0];

        self::assertSame(300.0, $svg['width']);
        self::assertSame(150.0, $svg['height']);
        self::assertSame(1.0, $svg['scaleX']);
        self::assertSame(10.0, $svg['left']);
    }

    /**
     * An image placed with `x`/`y`/`width` and no height takes the picture's own
     * proportion — the grid offers no band to fall back on, and a square would
     * distort the stand-in for no reason.
     */
    public function testAnImageWithoutAHeightTakesItsPicturesProportion(): void
    {
        $object = $this->compile([
            'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS],
            'elements' => [
                ['kind' => 'image', 'id' => 'photo', 'asset' => self::ASSET_PHOTO, 'x' => 0, 'y' => 0, 'width' => 400, 'input' => ['name' => 'Foto']],
            ],
        ])->objects()[0];

        self::assertSame(0.5, $object['scaleX']);
        self::assertSame(0.5, $object['scaleY'], '400 wide → 300 tall, the 4:3 of the picture');
    }

    /**
     * A gallery id that resolves to nothing — another project's picture, a
     * trashed one, a hallucinated UUID — is refused with the PATH of the element
     * that named it, so the agent knows which of five images to fix.
     */
    public function testUnknownAssetIsRefusedWithTheOffendingElementsPath(): void
    {
        try {
            $this->compile([
                'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS],
                'elements' => [
                    ['kind' => 'text', 'id' => 'headline', 'text' => 'Ahoj', 'font' => self::FONT, 'size' => 40, 'x' => 0, 'y' => 0, 'width' => 500],
                    ['kind' => 'image', 'id' => 'photo', 'asset' => self::ASSET_MISSING, 'x' => 0, 'y' => 100, 'width' => 400, 'height' => 300],
                ],
            ]);
            self::fail('an unknown asset must not compile');
        } catch (DesignCompilationFailed $exception) {
            self::assertCount(1, $exception->violations);
            self::assertSame('elements[1].asset', $exception->violations[0]->path);
            self::assertSame(CompileErrorCode::AssetNotFound, $exception->violations[0]->code);
            self::assertStringContainsString('list_gallery', $exception->violations[0]->message);
        }
    }

    /**
     * Every project-context problem is reported at once, like the parser's:
     * five problems in one response are fixed in one turn, five responses take
     * five — and this tool also renders.
     */
    public function testEveryProjectContextProblemIsReportedAtOnce(): void
    {
        try {
            $this->compile([
                'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS, 'background' => ['image' => self::ASSET_MISSING]],
                'elements' => [
                    ['kind' => 'text', 'id' => 'a', 'text' => 'A', 'font' => 'Comic Sans', 'size' => 40, 'x' => 0, 'y' => 0, 'width' => 500],
                    ['kind' => 'image', 'id' => 'b', 'asset' => self::ASSET_MISSING, 'x' => 0, 'y' => 100, 'width' => 400, 'height' => 300],
                    ['kind' => 'text', 'id' => 'c', 'text' => 'C', 'font' => 'Papyrus', 'size' => 40, 'x' => 0, 'y' => 500, 'width' => 500],
                ],
            ]);
            self::fail('three problems must not compile');
        } catch (DesignCompilationFailed $exception) {
            self::assertSame(
                ['canvas.background.image', 'elements[0].font', 'elements[1].asset', 'elements[2].font'],
                array_map(
                    static fn (object $violation): string => (string) $violation->path,
                    $exception->violations,
                ),
            );
            self::assertStringContainsString('4 problems', $exception->getMessage());
            self::assertCount(4, $exception->toArray());
        }
    }

    /**
     * The `canvas.background.fill` shorthand becomes Fabric's canvas-level
     * `background` key — the one the renderer's slicer strips for a transparent
     * export — and composes with a background LAYER (a transparent PNG over a
     * colour), which is why only the image half of the shorthand conflicts.
     */
    public function testCanvasBackgroundFillIsEmittedAsTheFabricBackgroundKey(): void
    {
        $compiled = $this->compile([
            'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS, 'background' => ['fill' => '#111111']],
            'elements' => [
                ['kind' => 'text', 'id' => 'a', 'text' => 'A', 'font' => self::FONT, 'size' => 40, 'x' => 0, 'y' => 0, 'width' => 500],
            ],
        ]);

        self::assertSame('#111111', $compiled->canvas['background'] ?? null);

        $plain = $this->compile([
            'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS],
            'elements' => [
                ['kind' => 'text', 'id' => 'a', 'text' => 'A', 'font' => self::FONT, 'size' => 40, 'x' => 0, 'y' => 0, 'width' => 500],
            ],
        ]);

        self::assertArrayNotHasKey('background', $plain->canvas);
    }

    /**
     * The `canvas.background.image` shorthand compiles to the same stack-index-0
     * layer a `kind: "background"` element does, and gets a stable identity of
     * its own so it keeps its `inputId` across `set_design` despite having no
     * authored slug.
     */
    public function testCanvasBackgroundImageShorthandCompilesToTheSameLayer(): void
    {
        $design = [
            'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS, 'background' => ['image' => self::ASSET_BACKGROUND]],
            'elements' => [
                ['kind' => 'text', 'id' => 'a', 'text' => 'A', 'font' => self::FONT, 'size' => 40, 'x' => 0, 'y' => 0, 'width' => 500],
            ],
        ];

        $compiled = $this->compile($design);
        $background = $compiled->objects()[0];

        self::assertTrue($background['isBackground']);
        self::assertSame('project-image/bg.jpg', $compiled->backgroundAssetPath);
        self::assertIsString($background['inputId']);

        $again = $this->compiler()->compile(
            DslParser::parse($design),
            $this->context(),
            DesignIdentity::fromMap([DesignCompiler::CANVAS_BACKGROUND_SLUG => $background['inputId']]),
        );

        self::assertSame($background['inputId'], $again->objects()[0]['inputId']);
    }

    /**
     * A fillable background flows into `imageInputs[]` with `isBackground: true`
     * and move/resize/rotate forced OFF — the fill is a deterministic cover over
     * the whole canvas, so there is no user transform to allow.
     */
    public function testFillableBackgroundBecomesAForcedCoverImageInput(): void
    {
        $compiled = $this->compile([
            'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS],
            'elements' => [
                ['kind' => 'background', 'id' => 'bg', 'asset' => self::ASSET_BACKGROUND, 'fillable' => true],
            ],
        ]);

        self::assertCount(1, $compiled->imageInputs);

        $input = $compiled->imageInputs[0];

        self::assertTrue($input->isBackground);
        self::assertFalse($input->allowMove);
        self::assertFalse($input->allowResize);
        self::assertFalse($input->allowRotate);
        self::assertSame($compiled->objects()[0]['inputId'], $input->inputId);
        self::assertTrue($compiled->objects()[0]['imagePlaceholder']);
    }

    /**
     * The text input's seven-key spec reaches BOTH faces — the canvas object and
     * the `EditorTextInput` — because the admin editor rebuilds `inputs[]` from
     * the canvas objects on save. A property missing from the object is not
     * merely absent from the JSON: it is gone from the input the moment a human
     * opens the variant and presses save.
     */
    public function testTextInputMetadataIsWrittenToBothTheObjectAndTheInput(): void
    {
        $compiled = $this->compile([
            'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS],
            'elements' => [
                [
                    'kind' => 'text', 'id' => 'headline', 'text' => 'SLEVA', 'font' => self::FONT_BOLD,
                    'size' => 96, 'color' => '#ffffff', 'align' => 'center', 'lineHeight' => 1.2,
                    'x' => 80, 'y' => 120, 'width' => 920,
                    'input' => [
                        'name' => 'Nadpis', 'maxLength' => 24, 'uppercase' => true,
                        'hidable' => true, 'locked' => false, 'richText' => true,
                        'sampleValue' => 'SLEVA 50 %',
                    ],
                ],
            ],
        ]);

        $object = $compiled->objects()[0];
        $input = $compiled->textInputs[0];

        self::assertSame(self::FONT_BOLD, $object['fontFamily']);
        self::assertSame(96.0, $object['fontSize']);
        self::assertSame('#ffffff', $object['fill']);
        self::assertSame('center', $object['textAlign']);
        self::assertSame(1.2, $object['lineHeight']);
        self::assertSame('SLEVA', $object['text']);

        foreach (['name' => 'Nadpis', 'maxLength' => 24, 'uppercase' => true, 'hidable' => true, 'locked' => false, 'richText' => true, 'sampleValue' => 'SLEVA 50 %'] as $key => $value) {
            self::assertSame($value, $object[$key], sprintf('object.%s', $key));
        }

        self::assertSame('Nadpis', $input->name);
        self::assertSame(24, $input->maxLength);
        self::assertTrue($input->uppercase);
        self::assertTrue($input->hidable);
        self::assertFalse($input->locked);
        self::assertTrue($input->richText);
        self::assertSame('SLEVA 50 %', $input->sampleValue);
        self::assertSame($object['inputId'], $input->inputId);
    }

    // =================================================================
    // fixtures
    // =================================================================

    /**
     * The designs every shape-wide invariant (§4.2-5, §4.2-7, §4.2-8, §4.4-19)
     * runs over. Each one exercises a different corner: a full layout, a
     * background-less design, a nested container forest, a text-only design.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function designProvider(): iterable
    {
        yield 'interleaved layout' => [self::interleavedDesign()];
        yield 'container with gap and spaceAfter' => [self::containerDesign()];
        yield 'nested containers' => [[
            'canvas' => ['width' => self::CANVAS, 'height' => 1350],
            'elements' => [
                ['kind' => 'text', 'id' => 'a', 'text' => 'A', 'font' => self::FONT, 'size' => 40, 'at' => ['area' => 'top', 'col' => [1, 12], 'marginX' => 80]],
                ['kind' => 'text', 'id' => 'b', 'text' => 'B', 'font' => self::FONT, 'size' => 40, 'x' => 80, 'y' => 500, 'width' => 900],
                ['kind' => 'text', 'id' => 'c', 'text' => 'C', 'font' => self::FONT, 'size' => 40, 'x' => 80, 'y' => 700, 'width' => 900],
                ['kind' => 'container', 'id' => 'inner', 'members' => ['b', 'c'], 'gap' => 12.34],
                ['kind' => 'container', 'id' => 'outer', 'members' => ['a'], 'children' => ['inner'], 'maxHeight' => 900, 'spaceAfter' => 40],
            ],
        ]];
        yield 'no background, text only' => [[
            'canvas' => ['width' => self::CANVAS, 'height' => 1920],
            'elements' => [
                ['kind' => 'text', 'id' => 'quote', 'text' => 'Bez pozadí', 'font' => self::FONT, 'size' => 64, 'at' => ['area' => 'middle', 'col' => [2, 11]]],
            ],
        ]];
        yield 'shapes among texts' => [self::shapeDesign()];
        yield 'fillable background over a full-bleed photo' => [[
            'canvas' => ['width' => 2480, 'height' => 3508, 'background' => ['fill' => '#0a0a0a']],
            'elements' => [
                ['kind' => 'background', 'id' => 'bg', 'asset' => self::ASSET_BACKGROUND, 'fillable' => true],
                ['kind' => 'image', 'id' => 'photo', 'asset' => self::ASSET_PHOTO, 'at' => ['area' => 'lower', 'col' => [1, 12]], 'input' => ['name' => 'Foto', 'hidable' => true]],
                ['kind' => 'image', 'id' => 'logo', 'asset' => self::ASSET_LOGO, 'at' => ['area' => 'top', 'col' => [9, 12], 'marginX' => 120]],
                ['kind' => 'text', 'id' => 'title', 'text' => 'A4', 'font' => self::FONT_BOLD, 'size' => 220, 'at' => ['area' => 'upper', 'col' => [1, 10], 'marginX' => 120, 'offsetY' => 200]],
            ],
        ]];
    }

    /**
     * One of every shape kind, plus a text, so the cross-cutting invariants
     * (stack order, no undeclared custom property, canonical re-parse) run over
     * every Fabric type a shape compiles to.
     *
     * @return array{canvas: array<string, mixed>, elements: list<array<string, mixed>>}
     */
    private static function shapeDesign(): array
    {
        $elements = [];
        $y = 0;

        foreach (CanvasShapeKind::cases() as $kind) {
            $element = [
                'kind' => 'shape',
                'id' => $kind->value,
                'shape' => $kind->value,
                'x' => 40,
                'y' => $y,
                'width' => 300,
                'height' => 120,
            ];

            if ($kind->supportsCornerRadius()) {
                $element['cornerRadius'] = 12;
            }

            $elements[] = $element;
            $y += 140;
        }

        $elements[] = [
            'kind' => 'shape', 'id' => 'gradient-panel', 'shape' => 'rectangle',
            'x' => 400, 'y' => 40, 'width' => 500, 'height' => 400,
            'fill' => ['type' => 'linear', 'angle' => 45, 'from' => '#ff0000', 'to' => '#0000ff'],
            'stroke' => '#00aa00', 'strokeWidth' => 6, 'strokeStyle' => 'dotted',
            'opacity' => 0.5, 'name' => 'Panel', 'locked' => true,
        ];
        $elements[] = [
            'kind' => 'shape', 'id' => 'radial-glow', 'shape' => 'ellipse',
            'x' => 400, 'y' => 500, 'width' => 500, 'height' => 250,
            'fill' => ['type' => 'radial', 'from' => '#ffcc00', 'to' => '#cc0033'],
            'name' => 'Glow',
        ];
        $elements[] = [
            'kind' => 'text', 'id' => 'caption', 'text' => 'Popisek',
            'font' => self::FONT, 'size' => 32, 'x' => 400, 'y' => 800, 'width' => 500,
        ];

        return [
            'canvas' => ['width' => self::CANVAS, 'height' => 1350],
            'elements' => $elements,
        ];
    }

    /**
     * A background, three texts and two images, interleaved so the positional
     * contract has something to get wrong.
     *
     * @return array{canvas: array<string, mixed>, elements: list<array<string, mixed>>}
     */
    private static function interleavedDesign(): array
    {
        return [
            'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS],
            'elements' => [
                ['kind' => 'background', 'id' => 'bg', 'asset' => self::ASSET_BACKGROUND],
                ['kind' => 'text', 'id' => 'headline', 'text' => 'SLEVA', 'font' => self::FONT_BOLD, 'size' => 96, 'x' => 80, 'y' => 120, 'width' => 920],
                [
                    'kind' => 'image', 'id' => 'photo', 'asset' => self::ASSET_PHOTO,
                    'x' => 0, 'y' => 300, 'width' => 540, 'height' => 405,
                    'input' => ['name' => 'Foto', 'allowRotate' => false, 'allowedDirectories' => ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']],
                ],
                ['kind' => 'text', 'id' => 'subhead', 'text' => 'na vše', 'font' => self::FONT, 'size' => 48, 'x' => 80, 'y' => 260, 'width' => 920],
                ['kind' => 'image', 'id' => 'logo', 'asset' => self::ASSET_LOGO, 'x' => 800, 'y' => 40, 'width' => 200, 'height' => 100],
                ['kind' => 'text', 'id' => 'legal', 'text' => 'Platí do konce měsíce', 'font' => self::FONT, 'size' => 18, 'x' => 80, 'y' => 1000, 'width' => 920],
            ],
        ];
    }

    /**
     * Three stacked texts in one container, listed BOTTOM-UP so §4.4-16 has
     * something to re-derive.
     *
     * @return array<string, mixed>
     */
    private static function containerDesign(null|float $gap = 24.0, null|float $spaceAfter = 60.0): array
    {
        $container = ['kind' => 'container', 'id' => 'body', 'members' => ['legal', 'subhead', 'headline'], 'maxHeight' => 400];

        if ($gap !== null) {
            $container['gap'] = $gap;
        }

        if ($spaceAfter !== null) {
            $container['spaceAfter'] = $spaceAfter;
        }

        return [
            'canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS],
            'elements' => [
                ['kind' => 'text', 'id' => 'headline', 'text' => 'Nadpis', 'font' => self::FONT_BOLD, 'size' => 64, 'x' => 80, 'y' => 120, 'width' => 920],
                ['kind' => 'text', 'id' => 'subhead', 'text' => 'Podnadpis', 'font' => self::FONT, 'size' => 40, 'x' => 80, 'y' => 260, 'width' => 920],
                ['kind' => 'text', 'id' => 'legal', 'text' => 'Drobným písmem', 'font' => self::FONT, 'size' => 18, 'x' => 80, 'y' => 400, 'width' => 920],
                $container,
            ],
        ];
    }

    // =================================================================
    // helpers
    // =================================================================

    private function compiler(): DesignCompiler
    {
        return new DesignCompiler(new BackgroundLayer());
    }

    private function context(): CompilationContext
    {
        return new CompilationContext(
            allowedFonts: [self::FONT, self::FONT_BOLD],
            assets: [
                self::ASSET_PHOTO => new DesignAsset(
                    self::ASSET_PHOTO,
                    'project-image/photo.png',
                    'https://cdn.example.test/project-image/photo.png',
                    800,
                    600,
                ),
                self::ASSET_LOGO => new DesignAsset(
                    self::ASSET_LOGO,
                    'project-image/logo.svg',
                    'https://cdn.example.test/project-image/logo.svg',
                    null,
                    null,
                ),
                self::ASSET_BACKGROUND => new DesignAsset(
                    self::ASSET_BACKGROUND,
                    'project-image/bg.jpg',
                    'https://cdn.example.test/project-image/bg.jpg',
                    1620,
                    1080,
                ),
            ],
        );
    }

    /**
     * @param array<string, mixed> $design
     */
    private function compile(array $design): CompiledDesign
    {
        return $this->compiler()->compile(DslParser::parse($design), $this->context(), DesignIdentity::fresh());
    }

    /**
     * @param array<string, mixed> $design
     * @return list<string>
     */
    private function textSlugs(array $design): array
    {
        return array_map(
            static fn (TextElement $element): string => $element->id,
            DslParser::parse($design)->textElements(),
        );
    }

    private static function text(string $id, float $top): TextElement
    {
        return new TextElement(
            $id,
            ucfirst($id),
            self::FONT,
            40.0,
            '#000000',
            TextAlign::Left,
            1.16,
            new Placement(null, 80.0, $top, 900.0, null),
            new TextInputSpec(name: $id),
        );
    }

    private static function image(string $id, float $top, string $assetId, null|ImageInputSpec $input): ImageElement
    {
        return new ImageElement($id, $assetId, new Placement(null, 80.0, $top, 200.0, 100.0), $input);
    }

    private static function background(string $id, string $assetId): DesignElement
    {
        return new BackgroundElement($id, $assetId, false);
    }

    /**
     * `CANVAS_CUSTOM_PROPERTIES` as the JS declares it — parsed, never restated,
     * so `assets/controllers/canvas_custom_properties.js` stays the source of
     * truth §4.2-7 says it is.
     *
     * @return list<string>
     */
    private static function javaScriptCustomProperties(): array
    {
        $source = file_get_contents(__DIR__ . '/../../../assets/controllers/canvas_custom_properties.js');
        self::assertIsString($source);

        $matched = preg_match('/export const CANVAS_CUSTOM_PROPERTIES = \[(.+?)\];/s', $source, $matches);
        self::assertSame(1, $matched, 'the JS declaration was not found — has the file been restructured?');

        preg_match_all("/'([^']+)'/", $matches[1], $names);

        return $names[1];
    }
}
