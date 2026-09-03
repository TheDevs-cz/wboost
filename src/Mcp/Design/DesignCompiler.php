<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Ramsey\Uuid\Uuid;
use WBoost\Web\Exceptions\DesignCompilationFailed;
use WBoost\Web\Mcp\Design\Dsl\BackgroundElement;
use WBoost\Web\Mcp\Design\Dsl\DesignDocument;
use WBoost\Web\Mcp\Design\Dsl\ImageElement;
use WBoost\Web\Mcp\Design\Dsl\Rect;
use WBoost\Web\Mcp\Design\Dsl\ShapeElement;
use WBoost\Web\Mcp\Design\Dsl\TextElement;
use WBoost\Web\Mcp\Design\Geometry\GridResolver;
use WBoost\Web\Services\Editor\BackgroundLayer;
use WBoost\Web\Value\CanvasShape;
use WBoost\Web\Value\CanvasShapeGradient;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;

/**
 * DSL → Fabric canvas + `inputs[]` + `imageInputs[]`. The heart of the design
 * tools, and the one class in `src/Mcp/` whose bugs are invisible until an
 * export comes out wrong.
 *
 * Everything it does is dictated by plan §4, whose numbered invariants are
 * cited inline below and asserted one-per-test in `DesignCompilerTest`. The
 * three that decide whether a compiled variant works at all:
 *
 * 1. **The positional textbox↔input contract (§4.1-1).**
 *    {@see \WBoost\Web\Services\SocialNetwork\TextInputObjectBinder} binds the
 *    *i-th VISIBLE Textbox in `canvas.objects[]`* to `inputs[i]` — textbox
 *    `inputId` properties are unreliable post-v7-migration, so POSITION is the
 *    authority. Emitting the two arrays in different orders does not fail
 *    anywhere: it silently substitutes the wrong copy into the wrong box and
 *    draws the fill page's overlay in the wrong place. Here the two orders are
 *    the same walk of {@see DesignDocument::drawableElements()}, which is why
 *    they cannot disagree.
 * 2. **Custom properties are the designer's metadata (§4.2-7).** The admin
 *    editor rebuilds `inputs[]` from the CANVAS OBJECTS on every save
 *    (`buildVariantPayload`). A property this compiler does not write is
 *    therefore not merely missing from the JSON — it is gone from the input the
 *    moment a human opens the variant and presses save. {@see CANVAS_CUSTOM_PROPERTIES}
 *    mirrors `assets/controllers/canvas_custom_properties.js` and a drift-guard
 *    test parses that file to prove it.
 * 3. **The background is built, never drawn (§4.3-12).**
 *    {@see BackgroundLayer::buildObject()} owns the cover transform (least
 *    scale that covers, anchored top-left) shared with `coverForDimensions()`
 *    and `ImagePlacement::computeCover`. A second copy of that formula would
 *    drift from the editor by a pixel and from the group projector by a crop.
 *
 * ## Pure by construction
 *
 * `compile()` reads nothing but its arguments — the project's fonts and the
 * pictures it references arrive as a {@see CompilationContext} that
 * {@see CompilationContextFactory} assembled. It also PERSISTS NOTHING: §4.5
 * invariant 20 routes every canvas write through
 * `EditTemplateVariantCanvasEditor`, and the same handler is what keeps
 * `template_variant.background_image` in sync with the layer's `assetPath`
 * (§4.3-13). The output of this class is data; S5-T3 is what commits it.
 *
 * ## What it deliberately does NOT do
 *
 * Bounds checks, overlap detection, legibility floors, predicted container
 * overflow — all of that is the linter's (S4-T6). A compiler that also
 * second-guessed the design would have two ways to say no, and the agent would
 * learn only one of them.
 */
readonly final class DesignCompiler
{
    /**
     * Fabric document version stamped on the canvas and on every object.
     *
     * '5.2.4' rather than the running Fabric 7.3.1 on purpose: it is what
     * {@see BackgroundLayer::buildObject()} writes and what every canvas in the
     * database carries, `loadFromJSON` ignores it, and a compiler that emitted
     * a different string would make its output trivially distinguishable from
     * an editor save for no benefit.
     */
    public const string CANVAS_VERSION = '5.2.4';

    /**
     * Mirror of `CANVAS_CUSTOM_PROPERTIES` in
     * `assets/controllers/canvas_custom_properties.js` — **that file is the
     * source of truth** (plan §4.2-7, risk R7) and `DesignCompilerTest` parses
     * it to assert these two lists agree.
     *
     * The list is the full contract, not the subset DSL v1 happens to author:
     * see {@see TEXT_CUSTOM_PROPERTIES} / {@see IMAGE_CUSTOM_PROPERTIES} for
     * what is actually written today. Keeping the whole set named here is what
     * makes the drift guard meaningful — a property added to the editor shows
     * up as a failing test in this stage rather than as designer metadata
     * quietly lost on the next save.
     *
     * @var list<string>
     */
    public const array CANVAS_CUSTOM_PROPERTIES = [
        'name', 'maxLength', 'locked', 'uppercase', 'description', 'hidable', 'richText', 'inputId',
        'lists', 'listBullet', 'listBulletImage', 'listIndent', 'listItemSpacing', 'listBlockSpacing',
        'listCheckboxes', 'listCheckboxImage', 'listCheckboxCheckedImage',
        'checklist', 'checklistAdd', 'checklistRemove', 'checklistEditText', 'checklistToggle',
        'sampleValue', 'allowedFonts', 'fontChoice', 'allowedColors',
        'imagePlaceholder', 'allowMove', 'allowResize', 'allowRotate', 'allowedDirectoryIds',
        'assetPath', 'assetId', 'editorLocked', 'isBackground', 'shapeKind',
    ];

    /**
     * The custom properties a compiled TEXTBOX carries — the
     * {@see \WBoost\Web\Mcp\Design\Dsl\TextInputSpec} keys plus `inputId` and
     * the always-null `description` (the DSL has no word for it yet, and
     * writing the key with its resolved value keeps the canvas shape stable).
     *
     * The list machinery (`lists`, `listBullet`, the checklist component, …) is
     * Stage-6+ DSL surface. Not writing those keys leaves the editor's own
     * defaults in force, which is the same state a freshly added textbox is in.
     *
     * @var list<string>
     */
    public const array TEXT_CUSTOM_PROPERTIES = [
        'inputId', 'name', 'maxLength', 'locked', 'uppercase', 'description', 'hidable', 'richText', 'sampleValue', 'allowedFonts', 'fontChoice', 'allowedColors',
    ];

    /**
     * The custom properties a compiled IMAGE may carry. Which of them it
     * actually gets depends on the object: the per-slot limits are written only
     * for a fillable placeholder (they mean nothing on a decorative picture),
     * `assetPath`/`assetId` only when the image is backed by a gallery row, and
     * `isBackground`/`editorLocked` only on the layer
     * {@see BackgroundLayer::buildObject()} builds.
     *
     * @var list<string>
     */
    public const array IMAGE_CUSTOM_PROPERTIES = [
        'inputId', 'name', 'description', 'imagePlaceholder', 'hidable',
        'allowMove', 'allowResize', 'allowRotate', 'allowedDirectoryIds',
        'assetPath', 'assetId', 'isBackground', 'editorLocked',
    ];

    /**
     * The custom properties a compiled SHAPE carries. Short by design: a shape
     * is decorative, so none of the input machinery applies to it. `shapeKind`
     * is what keeps a čtverec from decompiling as an obdélník (both are a
     * `Rect`), and `editorLocked` is the same editor-only lock images use.
     *
     * Everything else about a shape — fill, stroke, dash, corner radius,
     * opacity — is a NATIVE Fabric property and therefore not listed here.
     *
     * @var list<string>
     */
    public const array SHAPE_CUSTOM_PROPERTIES = [
        'inputId', 'shapeKind', 'name', 'editorLocked',
    ];

    /**
     * Identity slot for a background declared through the `canvas.background.image`
     * shorthand rather than as an element, which therefore has no slug of its own.
     *
     * It begins with `_`, so {@see \WBoost\Web\Mcp\Design\Dsl\DslParser::SLUG_PATTERN}
     * (which requires `[a-z0-9]` first) can never produce it — no authored slug
     * can collide with it, and the background keeps its `inputId` across
     * `set_design` calls exactly like every named element.
     */
    public const string CANVAS_BACKGROUND_SLUG = '_canvas_background';

    /**
     * 1×1 transparent PNG, used as the `src` of an image element that has no
     * picture yet.
     *
     * An empty or public-URL `src` is not an option: headless Chromium cannot
     * reach Minio, and a `src` Fabric fails to load makes `loadFromJSON` never
     * settle — the render then hangs until Gotenberg's timeout rather than
     * failing. The renderer stubs sliced-out images with the identical pixel for
     * the identical reason (`TemplateVariantImageRenderer::TRANSPARENT_PIXEL`).
     */
    private const string TRANSPARENT_PIXEL =
        'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function __construct(
        private BackgroundLayer $backgroundLayer,
    ) {
    }

    /**
     * @param DesignIdentity $identity what the slugs already mean on the variant
     *        being replaced; {@see DesignIdentity::fresh()} for a new design
     *
     * @throws DesignCompilationFailed when the document names a font or a
     *         gallery image this project does not have — every such problem at
     *         once, never the first one only
     */
    public function compile(
        DesignDocument $document,
        CompilationContext $context,
        DesignIdentity $identity,
    ): CompiledDesign {
        /** @var list<CompileViolation> $violations */
        $violations = [];

        $canvasSpec = $document->canvas;
        $inputIds = $this->assignInputIds($document, $identity);
        $indexById = $this->indexById($document);

        /** @var list<array<string, mixed>> $objects */
        $objects = [];
        /** @var list<EditorTextInput> $textInputs */
        $textInputs = [];
        /** @var list<EditorImageInput> $imageInputs */
        $imageInputs = [];
        /** @var array<string, array<string, mixed>> $objectByInputId */
        $objectByInputId = [];

        // ---- The background layer, at stack index 0 (§4.3-11). It is emitted
        // FIRST regardless of where the element sits in `elements[]`, because
        // the stack position of a background is not the author's to choose.
        $background = $this->compileBackground($document, $context, $inputIds, $indexById, $violations);

        if ($background !== null) {
            $objects[] = $background['object'];

            if ($background['input'] !== null) {
                $imageInputs[] = $background['input'];
            }
        }

        // ---- Everything else, in the author's stack order (bottom → top).
        foreach ($document->drawableElements() as $element) {
            if ($element instanceof BackgroundElement) {
                continue; // already emitted above, or refused
            }

            $index = $indexById[$element->id] ?? 0;
            $inputId = $inputIds[$element->id];
            $rect = GridResolver::resolvePlacement($element->placement, $canvasSpec);

            if ($element instanceof TextElement) {
                if (!$context->allowsFont($element->font)) {
                    $violations[] = self::fontNotAllowed($index, $element->font, $context->allowedFonts);
                }

                // The font choice names faces the same way `font` does, and an
                // unknown one is the same hard error — offering the end user a
                // font that does not exist is not a design anyone can fill.
                foreach ($element->input->allowedFonts as $position => $family) {
                    if (!$context->allowsFont($family)) {
                        $violations[] = self::fontNotAllowed($index, $family, $context->allowedFonts, sprintf('input.allowedFonts[%d]', $position));
                    }
                }

                $object = $this->compileTextObject($element, $rect, $inputId);
                $objects[] = $object;
                $objectByInputId[$inputId] = $object;
                // §4.1-1: this append and the object append above are the same
                // walk, so `inputs[i]` is the i-th Textbox by construction.
                $textInputs[] = $this->compileTextInput($element, $inputId);

                continue;
            }

            if ($element instanceof ShapeElement) {
                // Decorative by definition: an object and nothing else. No
                // input DTO, so nothing is appended to $textInputs (which would
                // break the positional textbox↔input contract) nor to
                // $imageInputs. It still joins $objectByInputId, because a
                // shape may be a container member.
                $object = $this->compileShapeObject($element, $rect, $inputId);
                $objects[] = $object;
                $objectByInputId[$inputId] = $object;

                continue;
            }

            $asset = null;

            if ($element->assetId !== null) {
                $asset = $context->asset($element->assetId);

                if ($asset === null) {
                    $violations[] = self::assetNotFound(sprintf('elements[%d].asset', $index), $element->assetId);
                }
            }

            $object = $this->compileImageObject($element, $rect, $inputId, $asset);
            $objects[] = $object;
            $objectByInputId[$inputId] = $object;

            if ($element->isPlaceholder()) {
                $imageInputs[] = $this->compileImageInput($element, $inputId);
            }
        }

        if ($violations !== []) {
            throw DesignCompilationFailed::fromViolations($violations);
        }

        $canvas = [
            'version' => self::CANVAS_VERSION,
            'objects' => $objects,
            'containers' => $this->compileContainers($document, $inputIds, $objectByInputId, $canvasSpec->height),
        ];

        if ($canvasSpec->backgroundFill !== null) {
            // Fabric serializes `Canvas.backgroundColor` under `background`; it
            // is what the renderer's slicer strips for a transparent export.
            $canvas['background'] = $canvasSpec->backgroundFill;
        }

        return new CompiledDesign(
            canvas: $canvas,
            textInputs: $textInputs,
            imageInputs: $imageInputs,
            backgroundAssetPath: $background === null ? null : $background['assetPath'],
        );
    }

    // -----------------------------------------------------------------
    // identity
    // -----------------------------------------------------------------

    /**
     * `slug → inputId` for every drawable element, preserving what the variant
     * already uses and minting UUID **v4** for the rest (§4.1-2; see
     * {@see DesignIdentity} for why v4 and not the house v7).
     *
     * Two slugs are never allowed to resolve to the same UUID even when a
     * caller hands in a map that says so: duplicate `inputId`s break the
     * first-match lookups in the renderer and the frame binder, so the second
     * claimant mints instead. Reachable only from a hand-built
     * {@see DesignIdentity::fromMap()}, and cheap insurance against it.
     *
     * @return array<string, string>
     */
    private function assignInputIds(DesignDocument $document, DesignIdentity $identity): array
    {
        /** @var array<string, string> $assigned */
        $assigned = [];
        /** @var array<string, true> $used */
        $used = [];

        $claim = static function (string $slug) use ($identity, &$assigned, &$used): void {
            $existing = $identity->existing($slug);

            if ($existing === null || isset($used[$existing])) {
                $existing = Uuid::uuid4()->toString();
            }

            $assigned[$slug] = $existing;
            $used[$existing] = true;
        };

        foreach ($document->drawableElements() as $element) {
            $claim($element->id);
        }

        if ($document->backgroundElement() === null && $document->canvas->backgroundImageAssetId !== null) {
            $claim(self::CANVAS_BACKGROUND_SLUG);
        }

        return $assigned;
    }

    /**
     * `slug → position in elements[]`, so a violation can address the element
     * the author wrote (`elements[2].font`) rather than the one the compiler
     * happens to be holding.
     *
     * @return array<string, int>
     */
    private function indexById(DesignDocument $document): array
    {
        $indexes = [];

        foreach ($document->elements as $index => $element) {
            $indexes[$element->id] = $index;
        }

        return $indexes;
    }

    // -----------------------------------------------------------------
    // background (§4.3)
    // -----------------------------------------------------------------

    /**
     * The stack-index-0 layer, or null when the design has none.
     *
     * A design with no background renders a TRANSPARENT PNG and that is legal,
     * not an error (§4.3-14) — the renderer calls Gotenberg's `omitBackground()`
     * and the editor shows a checkerboard. So "no asset" is answered with no
     * object, not with a stand-in.
     *
     * The one refusal: `fillable: true` with no asset. The Phase-B contract is
     * that an unfilled background slot renders the DESIGNED picture; a fillable
     * background without one promises a stand-in that does not exist, and
     * compiling it would silently drop the `fillable` flag (there being no
     * object to carry it). Told, rather than swallowed.
     *
     * @param array<string, string> $inputIds
     * @param array<string, int> $indexById
     * @param list<CompileViolation> $violations
     * @return null|array{object: array<string, mixed>, input: null|EditorImageInput, assetPath: string}
     */
    private function compileBackground(
        DesignDocument $document,
        CompilationContext $context,
        array $inputIds,
        array $indexById,
        array &$violations,
    ): null|array {
        $element = $document->backgroundElement();
        $assetId = $element !== null ? $element->assetId : $document->canvas->backgroundImageAssetId;
        $fillable = $element !== null && $element->fillable;
        $slug = $element !== null ? $element->id : self::CANVAS_BACKGROUND_SLUG;
        $path = $element !== null
            ? sprintf('elements[%d].asset', $indexById[$element->id] ?? 0)
            : 'canvas.background.image';

        if ($assetId === null) {
            if ($fillable) {
                $violations[] = new CompileViolation(
                    $path,
                    CompileErrorCode::InertDeclaration,
                    sprintf(
                        '%s: a fillable background needs an "asset" — the designed picture that renders when the user fills nothing. Either give it one, or set "fillable": false for a transparent background.',
                        $path,
                    ),
                );
            }

            return null;
        }

        $asset = $context->asset($assetId);

        if ($asset === null) {
            $violations[] = self::assetNotFound($path, $assetId);

            return null;
        }

        // §4.3-12: the cover transform is BackgroundLayer's, never ours.
        $object = $this->backgroundLayer->buildObject(
            $asset->url,
            $asset->path,
            $asset->width,
            $asset->height,
            (float) $document->canvas->width,
            (float) $document->canvas->height,
            $inputIds[$slug] ?? null,
        );

        // `assetId` is not BackgroundLayer's to know (its other callers write a
        // freshly uploaded file, not a gallery row) — but here it is known, and
        // §4.2-9 wants it recorded. Merged onto the built object rather than
        // computed alongside it, so the transform stays untouched.
        $object['assetId'] = $asset->id;

        $inputId = is_string($object['inputId']) ? $object['inputId'] : ($inputIds[$slug] ?? '');

        if (!$fillable) {
            return ['object' => $object, 'input' => null, 'assetPath' => $asset->path];
        }

        // A fillable background is a deterministic cover over the whole canvas,
        // so the user transform flags are forced off — the same rule
        // EditorImageInput::fromArray enforces defensively.
        $object['imagePlaceholder'] = true;
        $object['name'] = null;
        $object['description'] = null;
        $object['hidable'] = false;
        $object['allowMove'] = false;
        $object['allowResize'] = false;
        $object['allowRotate'] = false;
        $object['allowedDirectoryIds'] = [];

        return [
            'object' => $object,
            'input' => new EditorImageInput(
                inputId: $inputId,
                name: null,
                description: null,
                allowMove: false,
                allowResize: false,
                allowRotate: false,
                hidable: false,
                allowedDirectoryIds: [],
                isBackground: true,
            ),
            'assetPath' => $asset->path,
        ];
    }

    // -----------------------------------------------------------------
    // text (§4.2)
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function compileTextObject(TextElement $element, Rect $rect, string $inputId): array
    {
        return [
            'type' => 'Textbox',
            'version' => self::CANVAS_VERSION,
            // §4.2-5: Fabric v7 defaults both origins to 'center'. Every canvas
            // in the database — and the whole renderer — assumes top-left.
            'originX' => 'left',
            'originY' => 'top',
            'left' => $rect->x,
            'top' => $rect->y,
            // §4.2-6: `width` is the WRAP width and is authored; `height` is
            // Fabric's to compute from the wrapped content and is deliberately
            // absent. Authoring one desynchronises container reflow (which
            // measures the real wrapped height) from the design.
            'width' => $rect->width,
            'scaleX' => 1.0,
            'scaleY' => 1.0,
            'angle' => 0,
            'text' => $element->text,
            'fontFamily' => $element->font,
            'fontSize' => $element->size,
            'fill' => $element->color,
            'textAlign' => $element->align->value,
            'lineHeight' => $element->lineHeight,
            'charSpacing' => 0,
            'editable' => true,
            // §4.2-8: no lockScaling*/hasControls/selectable/evented — Fabric
            // does not serialize them and `applyTextboxDefaults()` re-derives
            // them on load. Authoring them would be state nobody reads.
            'inputId' => $inputId,
            'name' => $element->input->name,
            'maxLength' => $element->input->maxLength,
            'locked' => $element->input->locked,
            'uppercase' => $element->input->uppercase,
            'description' => null,
            'hidable' => $element->input->hidable,
            'richText' => $element->input->richText,
            'sampleValue' => $element->input->sampleValue,
            'allowedFonts' => $element->input->allowedFonts,
            'fontChoice' => $element->input->fontChoice,
            'allowedColors' => $element->input->allowedColors,
        ];
    }

    /**
     * §4.1-2: the same `inputId` the canvas object carries. The binder reaches
     * this entry positionally, but the renderer re-stamps the id from here onto
     * the object before applying overrides, so the two must agree.
     */
    private function compileTextInput(TextElement $element, string $inputId): EditorTextInput
    {
        return new EditorTextInput(
            inputId: $inputId,
            name: $element->input->name,
            maxLength: $element->input->maxLength,
            locked: $element->input->locked,
            uppercase: $element->input->uppercase,
            description: null,
            hidable: $element->input->hidable,
            richText: $element->input->richText,
            sampleValue: $element->input->sampleValue,
            allowedFonts: $element->input->allowedFonts,
            fontChoice: $element->input->fontChoice,
            allowedColors: $element->input->allowedColors,
        );
    }

    // -----------------------------------------------------------------
    // images (§4.2)
    // -----------------------------------------------------------------

    /**
     * A Fabric image object fitted into its resolved rect.
     *
     * Two fits, and the difference is not cosmetic:
     *
     * - a **fillable placeholder** fills the rect EXACTLY (`scaleX`/`scaleY`
     *   independent). The rect IS the slot frame — it is what
     *   `CanvasPlaceholderGeometry::frameFromObject()` publishes to the API's
     *   `imageInputs[].frame`, what the fill page draws its box at, and what
     *   `ImagePlacement` clips the user's picture to. A frame that quietly
     *   shrank to the stand-in's aspect ratio would be a lie told to three
     *   consumers at once;
     * - a **decorative image** is CONTAIN-fitted and centred in the rect
     *   (uniform scale). Nothing downstream reads its frame, and stretching a
     *   logo to a grid cell is never what the author meant.
     *
     * Both round-trip: a decompiled decorative image reports its displayed rect,
     * whose ratio already equals the picture's, so contain-fitting it again is
     * the identity.
     *
     * With no picture, or one whose natural size is unknowable (an SVG, an
     * unreadable object), the rect itself becomes the object's `width`/`height`
     * at scale 1 — the same fallback {@see BackgroundLayer::buildObject()} takes,
     * for the same reason: there is no ratio to preserve.
     *
     * @return array<string, mixed>
     */
    private function compileImageObject(
        ImageElement $element,
        Rect $rect,
        string $inputId,
        null|DesignAsset $asset,
    ): array {
        $frameWidth = $rect->width;
        $frameHeight = self::imageFrameHeight($rect, $asset);

        $left = $rect->x;
        $top = $rect->y;
        $width = $frameWidth;
        $height = $frameHeight;
        $scaleX = 1.0;
        $scaleY = 1.0;

        if ($asset !== null && $asset->hasNaturalSize()) {
            assert($asset->width !== null && $asset->height !== null);
            $width = (float) $asset->width;
            $height = (float) $asset->height;

            if ($element->isPlaceholder()) {
                $scaleX = $frameWidth / $width;
                $scaleY = $frameHeight / $height;
            } else {
                $scale = min($frameWidth / $width, $frameHeight / $height);
                $scaleX = $scale;
                $scaleY = $scale;
                $left = $rect->x + ($frameWidth - $width * $scale) / 2;
                $top = $rect->y + ($frameHeight - $height * $scale) / 2;
            }
        }

        $object = [
            'type' => 'Image',
            'version' => self::CANVAS_VERSION,
            'originX' => 'left',
            'originY' => 'top',
            'left' => $left,
            'top' => $top,
            // Fabric's `width`/`height` on an image are the NATURAL pixel size;
            // the displayed box is that times the scales (frameFromObject reads
            // exactly this product).
            'width' => $width,
            'height' => $height,
            'cropX' => 0,
            'cropY' => 0,
            'scaleX' => $scaleX,
            'scaleY' => $scaleY,
            'angle' => 0,
            // §4.2-9: `src` is the public URL and `assetPath` the storage key.
            // Without the latter AssetInliner cannot inline the picture and
            // headless Chromium — which has no route to Minio — paints nothing.
            // `$asset->url ?? …` is null-safe: `??` suppresses the property
            // fetch on a null object, and PHPStan rejects `?->` here as
            // redundant (the same shape `Placement::resolve()` uses).
            'src' => $asset->url ?? self::TRANSPARENT_PIXEL,
            'crossOrigin' => $asset === null ? null : 'anonymous',
            'inputId' => $inputId,
            'imagePlaceholder' => $element->isPlaceholder(),
            'name' => $element->input?->name,
            'description' => null,
        ];

        if ($asset !== null) {
            $object['assetPath'] = $asset->path;
            $object['assetId'] = $asset->id;
        }

        if ($element->isPlaceholder()) {
            assert($element->input !== null);
            $object['hidable'] = $element->input->hidable;
            $object['allowMove'] = $element->input->allowMove;
            $object['allowResize'] = $element->input->allowResize;
            $object['allowRotate'] = $element->input->allowRotate;
            $object['allowedDirectoryIds'] = $element->input->allowedDirectories;
        }

        return $object;
    }

    /**
     * A vector shape. Every visual property is a NATIVE Fabric key, so nothing
     * downstream needs teaching: `loadFromJSON` enlivens the built-in type and
     * the headless render paints it.
     *
     * Geometry is expressed as base dimensions at scale 1 wherever Fabric lets
     * it, and as a scale only where the type's own model forces it — a `Circle`
     * is defined by ONE radius, so the only way to give it the authored
     * non-square box (which is what a designer gets by dragging a corner
     * handle) is `scaleY`. Emitting it any other way would make a decompiled
     * circle un-representable and the round-trip lossy.
     *
     * @return array<string, mixed>
     */
    private function compileShapeObject(ShapeElement $element, Rect $rect, string $inputId): array
    {
        $width = $rect->width;
        $height = ShapeElement::frameHeight($rect);

        $object = [
            'type' => $element->shape->fabricType(),
            'version' => self::CANVAS_VERSION,
            'originX' => 'left',
            'originY' => 'top',
            'left' => $rect->x,
            'top' => $rect->y,
            'width' => $width,
            'height' => $height,
            'scaleX' => 1.0,
            'scaleY' => 1.0,
            'angle' => 0,
            'opacity' => $element->opacity,
            'fill' => $element->fill instanceof CanvasShapeGradient
                ? $element->fill->toFabric()
                : $element->fill,
            'stroke' => $element->stroke,
            'strokeWidth' => $element->strokeWidth,
            // The editor authors shapes strokeUniform, so the border keeps a
            // constant weight under scaling — and the group projector scales
            // `strokeWidth` itself to compensate. A compiled shape that said
            // otherwise would drift from an editor-made one the first time the
            // group fanned it out.
            'strokeUniform' => true,
            'strokeDashArray' => $element->strokeStyle->dashArray($element->strokeWidth),
            'strokeLineCap' => $element->strokeStyle->lineCap(),
            'inputId' => $inputId,
            'shapeKind' => $element->shape->value,
            'name' => $element->name,
            'editorLocked' => $element->locked,
        ];

        return match ($element->shape->fabricType()) {
            'Rect' => $object + ['rx' => $element->cornerRadius, 'ry' => $element->cornerRadius],
            'Circle' => array_merge($object, [
                'radius' => $width / 2,
                'height' => $width,
                'scaleY' => $width > 0.0 ? $height / $width : 1.0,
            ]),
            'Ellipse' => $object + ['rx' => $width / 2, 'ry' => $height / 2],
            'Polygon' => $object + ['points' => self::starPoints($width, $height)],
            default => $object,
        };
    }

    /**
     * A five-pointed star whose bounding box is exactly `$width × $height` —
     * the same figure `starPoints()` in `assets/controllers/canvas_shapes.js`
     * draws, normalised to the authored box instead of to a radius.
     *
     * Normalising is what makes the round-trip stable: the editor's star
     * already fills its own bbox exactly, so decompiling it to that box and
     * regenerating here reproduces the identical polygon (and a star the
     * designer stretched comes back stretched the same way).
     *
     * @return list<array{x: float, y: float}>
     */
    private static function starPoints(float $width, float $height): array
    {
        $spikes = 5;
        $innerRatio = 0.45;

        $raw = [];

        for ($i = 0; $i < $spikes * 2; $i++) {
            $radius = $i % 2 === 0 ? 0.5 : 0.5 * $innerRatio;
            $angle = (M_PI / $spikes) * $i - M_PI / 2;
            $raw[] = ['x' => $radius * cos($angle), 'y' => $radius * sin($angle)];
        }

        $xs = array_column($raw, 'x');
        $ys = array_column($raw, 'y');
        $spanX = max($xs) - min($xs);
        $spanY = max($ys) - min($ys);
        $minX = min($xs);
        $minY = min($ys);

        return array_map(
            static fn (array $point): array => [
                'x' => round($spanX > 0.0 ? (($point['x'] - $minX) / $spanX) * $width : 0.0, 4),
                'y' => round($spanY > 0.0 ? (($point['y'] - $minY) / $spanY) * $height : 0.0, 4),
            ],
            $raw,
        );
    }

    /**
     * The rect's height, or — when the author gave none and the placement had
     * no band to offer one — a height derived from the picture's own ratio, so
     * `{"x": …, "y": …, "width": 400}` places a photo without distorting it.
     * With no picture either, a square: the honest default when nothing in the
     * document implies a proportion.
     *
     * Public and static because {@see \WBoost\Web\Mcp\Design\Lint\DesignLinter}
     * reports the bounds of the box this compiler emits, and a second copy of
     * this fallback would have the linter warning about a rectangle nothing
     * draws.
     */
    public static function imageFrameHeight(Rect $rect, null|DesignAsset $asset): float
    {
        if ($rect->height !== null) {
            return $rect->height;
        }

        if ($asset !== null && $asset->hasNaturalSize()) {
            assert($asset->width !== null && $asset->height !== null);

            return $rect->width * ($asset->height / $asset->width);
        }

        return $rect->width;
    }

    /**
     * §4.1-4: only images marked `imagePlaceholder: true` become
     * `imageInputs[]`, and they bind by their OWN `inputId` — image objects
     * carry a reliable one (unlike textboxes), so there is no positional
     * contract to honour here.
     */
    private function compileImageInput(ImageElement $element, string $inputId): EditorImageInput
    {
        assert($element->input !== null);

        return new EditorImageInput(
            inputId: $inputId,
            name: $element->input->name,
            description: null,
            allowMove: $element->input->allowMove,
            allowResize: $element->input->allowResize,
            allowRotate: $element->input->allowRotate,
            hidable: $element->input->hidable,
            allowedDirectoryIds: $element->input->allowedDirectories,
            isBackground: false,
        );
    }

    // -----------------------------------------------------------------
    // containers (§4.4)
    // -----------------------------------------------------------------

    /**
     * The `containers` key of the canvas document (§4.4-15), sanitized to the
     * same fixpoint as `sanitizedContainers()` in
     * `assets/controllers/canvas_payload.js` (§4.4-17).
     *
     * The JS is mirrored step for step and in the same ORDER, because the order
     * is load-bearing: pruning members before validating child references means
     * a container emptied by pruning is dropped, which can strip its parent
     * below the two-item minimum — hence the closing fixpoint loop rather than
     * a single pass.
     *
     *  1. members → the `inputId`s of objects that are member CANDIDATES
     *     (textboxes and decorative images; §4.4-18 keeps fillable placeholders
     *     and the background out, exactly as `isMemberCandidate()` does);
     *  2. flow order re-derived from the designed `top` (§4.4-16) — the DSL's
     *     member order is not consulted, because an author who moves an element
     *     up must not have to remember to reorder a list as well;
     *  3. `id` non-empty and `maxHeight > 0` (what `CanvasContainer::fromArray()`
     *     will accept);
     *  4. children: known ids only, no self-reference, one parent per child
     *     (first claimant wins, in list order);
     *  5. cycles: a child reference that reaches back to an ancestor is dropped;
     *  6. fixpoint: containers with fewer than 2 items (members + children)
     *     are dropped and the survivors re-filtered, until nothing changes.
     *
     * @param array<string, string> $inputIds
     * @param array<string, array<string, mixed>> $objectByInputId
     * @return list<array{id: string, maxHeight: float, memberInputIds: list<string>, memberContainerIds: list<string>, gap?: float, spaceAfter?: float}>
     */
    private function compileContainers(
        DesignDocument $document,
        array $inputIds,
        array $objectByInputId,
        int $canvasHeight,
    ): array {
        $containers = $document->containerElements();

        if ($containers === []) {
            return [];
        }

        $containerIds = [];

        foreach ($containers as $container) {
            $containerIds[$container->id] = true;
        }

        /** @var list<array{id: string, maxHeight: float, memberInputIds: list<string>, memberContainerIds: list<string>, gap?: float, spaceAfter?: float}> $result */
        $result = [];

        foreach ($containers as $container) {
            $memberInputIds = [];

            foreach ($container->memberIds as $memberSlug) {
                $inputId = $inputIds[$memberSlug] ?? null;
                $object = $inputId === null ? null : ($objectByInputId[$inputId] ?? null);

                if ($inputId !== null && $object !== null && self::isMemberCandidate($object)) {
                    $memberInputIds[] = $inputId;
                }
            }

            $memberContainerIds = [];

            foreach ($container->childIds as $childSlug) {
                if (isset($containerIds[$childSlug])) {
                    $memberContainerIds[] = $childSlug;
                }
            }

            $entry = [
                // The container's canvas id IS its DSL slug: stable across
                // `set_design` (so `memberContainerIds` and the strict-export
                // 400's `containerId` keep meaning the same thing), and unique
                // because the parser makes every element slug unique.
                'id' => $container->id,
                // A nested container's height is not a bound — only the root's
                // gates overflow — but `CanvasContainer::fromArray()` drops a
                // non-positive one, so a null becomes an inert positive value.
                // The canvas height is that value: ignored where it is ignored,
                // and sane if the child is ever promoted to a root.
                'maxHeight' => $container->maxHeight ?? (float) $canvasHeight,
                'memberInputIds' => self::sortByDesignedTop($memberInputIds, $objectByInputId),
                'memberContainerIds' => $memberContainerIds,
            ];

            $gap = self::spacing($container->gap);

            if ($gap !== null) {
                $entry['gap'] = $gap;
            }

            $spaceAfter = self::spacing($container->spaceAfter);

            if ($spaceAfter !== null) {
                $entry['spaceAfter'] = $spaceAfter;
            }

            if ($entry['id'] !== '' && $entry['maxHeight'] > 0) {
                $result[] = $entry;
            }
        }

        $result = self::pruneChildReferences($result);
        $result = self::dropCyclicChildReferences($result);

        return self::dropDegenerateToFixpoint($result);
    }

    /**
     * Mirrors `isMemberCandidate()` in `assets/editor/container_layout.js`:
     * textboxes, images that are neither fillable placeholders nor the
     * background layer (§4.4-18 — their frames are load-bearing elsewhere),
     * and vector shapes (always decorative, so nothing to exclude).
     *
     * @param array<string, mixed> $object
     */
    private static function isMemberCandidate(array $object): bool
    {
        $type = strtolower(is_string($object['type'] ?? null) ? $object['type'] : '');

        if ($type === 'textbox') {
            return true;
        }

        if (CanvasShape::isShapeType($type)) {
            return true;
        }

        return $type === 'image'
            && ($object['imagePlaceholder'] ?? false) !== true
            && ($object['isBackground'] ?? false) !== true;
    }

    /**
     * §4.4-16: flow order is ascending designed `top`, re-derived from the
     * emitted objects. Ties keep their list order (PHP's sort is stable since
     * 8.0), matching `sortMemberIdsByTop()`'s stable JS sort.
     *
     * @param list<string> $memberInputIds
     * @param array<string, array<string, mixed>> $objectByInputId
     * @return list<string>
     */
    private static function sortByDesignedTop(array $memberInputIds, array $objectByInputId): array
    {
        usort($memberInputIds, static function (string $a, string $b) use ($objectByInputId): int {
            $topA = $objectByInputId[$a]['top'] ?? 0;
            $topB = $objectByInputId[$b]['top'] ?? 0;

            return (is_int($topA) || is_float($topA) ? (float) $topA : 0.0)
                <=> (is_int($topB) || is_float($topB) ? (float) $topB : 0.0);
        });

        return $memberInputIds;
    }

    /**
     * `gap` / `spaceAfter` normalization: finite and ≥ 0, rounded to one
     * decimal exactly as the JS does (`Math.round(v * 10) / 10`), or absent.
     */
    private static function spacing(null|float $value): null|float
    {
        if ($value === null || !is_finite($value) || $value < 0.0) {
            return null;
        }

        return round($value, 1);
    }

    /**
     * Children must name a surviving container, must not be the container
     * itself, and must have exactly one parent — the first that claims them in
     * list order.
     *
     * @param list<array{id: string, maxHeight: float, memberInputIds: list<string>, memberContainerIds: list<string>, gap?: float, spaceAfter?: float}> $containers
     * @return list<array{id: string, maxHeight: float, memberInputIds: list<string>, memberContainerIds: list<string>, gap?: float, spaceAfter?: float}>
     */
    private static function pruneChildReferences(array $containers): array
    {
        $known = [];

        foreach ($containers as $container) {
            $known[$container['id']] = true;
        }

        /** @var array<string, true> $claimed */
        $claimed = [];

        foreach ($containers as $index => $container) {
            $children = [];

            foreach ($container['memberContainerIds'] as $childId) {
                if ($childId === $container['id'] || !isset($known[$childId]) || isset($claimed[$childId])) {
                    continue;
                }

                $claimed[$childId] = true;
                $children[] = $childId;
            }

            $containers[$index]['memberContainerIds'] = $children;
        }

        return $containers;
    }

    /**
     * Drop child references that close a cycle — a nested container forest must
     * be a tree, or the layout engine recurses forever.
     *
     * **The graph is updated as it is walked, not snapshotted.** That mirrors
     * the JS, where `byId` holds the container OBJECTS and the filter mutates
     * `memberContainerIds` in place, so a container processed later sees the
     * edges its predecessors already gave up. The difference is visible on a
     * two-node cycle: mutating breaks it once (the first container loses its
     * child, the second keeps its own and the pair becomes a tree), snapshotting
     * would break it twice and dissolve the nesting entirely. Matching the
     * editor matters more than symmetry — a design saved from the browser and
     * the same design compiled here must produce the same `containers`.
     *
     * @param list<array{id: string, maxHeight: float, memberInputIds: list<string>, memberContainerIds: list<string>, gap?: float, spaceAfter?: float}> $containers
     * @return list<array{id: string, maxHeight: float, memberInputIds: list<string>, memberContainerIds: list<string>, gap?: float, spaceAfter?: float}>
     */
    private static function dropCyclicChildReferences(array $containers): array
    {
        $byId = [];

        foreach ($containers as $container) {
            $byId[$container['id']] = $container['memberContainerIds'];
        }

        foreach ($containers as $index => $container) {
            $children = [];

            foreach ($container['memberContainerIds'] as $childId) {
                if (!self::reaches($childId, $container['id'], $byId, [])) {
                    $children[] = $childId;
                }
            }

            $containers[$index]['memberContainerIds'] = $children;
            $byId[$container['id']] = $children;
        }

        return $containers;
    }

    /**
     * Mirrors the JS `reaches()`: is `$targetId` reachable from `$fromId` by
     * following child references?
     *
     * @param array<string, list<string>> $byId
     * @param array<string, true> $seen
     */
    private static function reaches(string $fromId, string $targetId, array $byId, array $seen): bool
    {
        if ($fromId === $targetId) {
            return true;
        }

        if (isset($seen[$fromId])) {
            return false;
        }

        $seen[$fromId] = true;

        foreach ($byId[$fromId] ?? [] as $childId) {
            if (self::reaches($childId, $targetId, $byId, $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * §4.4-17's fixpoint: a container needs 2+ items counting nested children,
     * and dropping one can invalidate the parent that counted it. Iterate until
     * the surviving set stops shrinking — a single pass leaves inert
     * definitions behind, which `CanvasContainer` would then drop silently and
     * asymmetrically from the design the agent was shown.
     *
     * @param list<array{id: string, maxHeight: float, memberInputIds: list<string>, memberContainerIds: list<string>, gap?: float, spaceAfter?: float}> $containers
     * @return list<array{id: string, maxHeight: float, memberInputIds: list<string>, memberContainerIds: list<string>, gap?: float, spaceAfter?: float}>
     */
    private static function dropDegenerateToFixpoint(array $containers): array
    {
        for (;;) {
            $valid = [];

            foreach ($containers as $container) {
                if (count($container['memberInputIds']) + count($container['memberContainerIds']) >= 2) {
                    $valid[$container['id']] = true;
                }
            }

            if (count($valid) === count($containers)) {
                break;
            }

            $next = [];

            foreach ($containers as $container) {
                if (!isset($valid[$container['id']])) {
                    continue;
                }

                $container['memberContainerIds'] = array_values(array_filter(
                    $container['memberContainerIds'],
                    static fn (string $childId): bool => isset($valid[$childId]),
                ));

                $next[] = $container;
            }

            $containers = $next;
        }

        return $containers;
    }

    // -----------------------------------------------------------------
    // violations
    // -----------------------------------------------------------------

    /**
     * §4.2-10, mirroring the export API's `font_not_allowed`: name the value
     * that failed AND the ones that would work, so the agent fixes it in the
     * same turn.
     *
     * Public because {@see \WBoost\Web\Mcp\Design\Lint\DesignLinter} reports the
     * same problem one stage earlier (so `preview_design` can list it beside
     * every other finding instead of dying on it), and it must say the same
     * words. Sharing the builder is what makes "the two cannot disagree" a fact
     * rather than a comment — the linter's test asserts the texts are identical.
     *
     * @param list<string> $allowed
     */
    public static function fontNotAllowed(int $index, string $font, array $allowed, string $key = 'font'): CompileViolation
    {
        $path = sprintf('elements[%d].%s', $index, $key);

        $message = $allowed === []
            ? sprintf(
                '%s: "%s" is not available — this project has no brand fonts uploaded yet, so no font can be used. Add one in the brand manual first.',
                $path,
                $font,
            )
            : sprintf(
                '%s: "%s" is not one of this project\'s fonts. Use one of: %s (exactly as written — call get_context for the list).',
                $path,
                $font,
                implode(', ', array_map(static fn (string $face): string => '"' . $face . '"', $allowed)),
            );

        return new CompileViolation($path, CompileErrorCode::FontNotAllowed, $message, $allowed);
    }

    private static function assetNotFound(string $path, string $assetId): CompileViolation
    {
        return new CompileViolation(
            $path,
            CompileErrorCode::AssetNotFound,
            sprintf(
                '%s: "%s" is not an image in this project\'s gallery (it may belong to another project, or have been deleted). Call list_gallery for the ids you can use.',
                $path,
                $assetId,
            ),
        );
    }
}
