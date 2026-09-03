<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Mcp\Design\Dsl\BackgroundElement;
use WBoost\Web\Mcp\Design\Dsl\CanvasSpec;
use WBoost\Web\Mcp\Design\Dsl\ContainerElement;
use WBoost\Web\Mcp\Design\Dsl\DesignDocument;
use WBoost\Web\Mcp\Design\Dsl\DesignElement;
use WBoost\Web\Mcp\Design\Dsl\DslParser;
use WBoost\Web\Mcp\Design\Dsl\ImageElement;
use WBoost\Web\Mcp\Design\Dsl\ImageInputSpec;
use WBoost\Web\Mcp\Design\Dsl\Placement;
use WBoost\Web\Mcp\Design\Dsl\PlacementArea;
use WBoost\Web\Mcp\Design\Dsl\SemanticPlacement;
use WBoost\Web\Mcp\Design\Dsl\ShapeElement;
use WBoost\Web\Mcp\Design\Dsl\TextAlign;
use WBoost\Web\Mcp\Design\Dsl\TextElement;
use WBoost\Web\Mcp\Design\Dsl\TextInputSpec;
use WBoost\Web\Mcp\Design\Geometry\GridResolver;
use WBoost\Web\Value\BackgroundMode;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\CanvasShape;
use WBoost\Web\Value\CanvasShapeGradient;
use WBoost\Web\Value\CanvasShapeKind;
use WBoost\Web\Value\CanvasShapeStroke;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\RichText;

/**
 * Fabric canvas + `inputs[]` + `imageInputs[]` → DSL. The inverse of
 * {@see DesignCompiler}, and what makes the design tools an EDITOR rather than
 * a generator: `get_design` / `describe_variant` show an existing variant as a
 * document, the agent changes three lines of it, `set_design` compiles it back.
 *
 * ## DSL v1 is a lossy projection, and this class refuses to pretend otherwise
 *
 * The compiler is a total function into Fabric; the reverse is not. Fabric
 * canvases authored in the browser routinely contain things DSL v1 has no word
 * for — rotation, per-character styles, gradients, shadows, image filters,
 * design-hidden layers, ruler guides, the whole list/checklist input machinery,
 * object types with no `kind` at all. **The expressible subset** is exactly:
 *
 * - `Textbox` objects that are visible, axis-aligned (`angle`/`skew`/`flip`
 *   neutral), uniformly scaled, single-fill, undecorated and unstyled per
 *   character;
 * - `Image` objects that are visible, axis-aligned, un-cropped, un-filtered,
 *   and whose picture is a GALLERY row (the DSL addresses pictures by gallery
 *   id — see {@see DecompilationContext});
 * - one background layer that is the canonical cover fit
 *   {@see \WBoost\Web\Services\Editor\BackgroundLayer::buildObject()} builds;
 * - the `containers` definitions, and a flat canvas `background` colour;
 * - the seven text-input keys and the seven image-input keys of plan §3.4.
 *
 * ## What happens to everything else: decompile, and REPORT
 *
 * The alternative — refusing a canvas the DSL cannot represent exactly — was
 * rejected. Almost every variant in production would be refused, so
 * `get_design` would be useful only on designs the agent itself authored, and
 * the edit story the DSL exists for would not exist. What must never happen is
 * the OTHER failure: an agent reading a design, editing one headline, writing
 * it back and silently deleting a designer's rotated logo. So every canvas
 * decompiles, and every thing that did not survive becomes a {@see DesignLoss}
 * on {@see DecompiledDesign::$losses} — with a path, a code, a sentence, and a
 * `destructive` flag. `get_design` renders that list; an agent that reads it
 * knows what it is about to break before it breaks it.
 *
 * The consequence for the plan's S4-T5 acceptance criterion ("`decompile →
 * compile` produces a canvas that renders identically") is stated plainly:
 * that holds for the expressible subset and CANNOT hold outside it. What does
 * hold everywhere, and is the property the round-trip test actually pins, is
 * **idempotence**: `decompile(compile(decompile(c)))` equals `decompile(c)`,
 * so a `get_design → set_design → get_design` loop converges after one step
 * instead of drifting for as long as an agent keeps editing.
 *
 * ## Slug naming goes through {@see DesignSlug}, in {@see DesignIdentity}'s walk order
 *
 * This is the single most dangerous thing in the class. Persisted inputs carry
 * no slug and `CANVAS_CUSTOM_PROPERTIES` is closed, so the slug cannot be
 * stashed on the object: it must be re-derived identically on both sides or a
 * `get_design → set_design` round trip re-mints every `inputId` and every
 * saved fill, every API consumer's `inputs[].id` and every container's
 * `memberInputIds` stops resolving — with nothing anywhere throwing.
 *
 * So the naming here is not merely "also uses {@see DesignSlug}": it walks in
 * exactly {@see DesignIdentity::fromInputs()}'s order — every text input in
 * `inputs[]` order, then every image input in `imageInputs[]` order, then
 * whatever is left over in stack order — so the two agree even on the case that
 * makes a second implementation drift, which is two inputs sharing a name.
 * `DesignDecompilerTest` asserts that equality directly.
 *
 * ## Semantic `at` vs absolute `x`/`y`/`width`
 *
 * Semantic placement is emitted **only when it is provably identical**: a
 * candidate `at` is built from the grid contract ({@see GridResolver}) with
 * zero margins and zero offsets, then RESOLVED and compared with the target
 * rect. Anything that does not match to within {@see GEOMETRY_EPSILON} falls
 * back to absolute pixels. A `{"area": "top", "offsetY": 137}` would be
 * "semantic" in shape and meaningless in content, and — worse — a placement
 * that resolved to a slightly different pixel would move the design a little
 * on every save. So the output is readable where the design really is on the
 * grid, and exact everywhere else.
 */
readonly final class DesignDecompiler
{
    /**
     * How close a resolved semantic placement must be to the stored geometry
     * before it is emitted instead of absolute pixels.
     *
     * Deliberately far tighter than the round-trip test's 0.01 comparison
     * tolerance: a semantic placement is only ever emitted when it reproduces
     * the pixel, so it can never be the thing that consumes the budget. Grid
     * edges are whole pixels ({@see GridResolver::columnEdge()}), so this is
     * float noise, not a fit.
     */
    public const float GEOMETRY_EPSILON = 1.0e-6;

    /**
     * The order semantic areas are tried in.
     *
     * The thirds come before the halves and `full` comes last, so an element
     * at `y = 0` reads as `top` rather than as the equally-true `upper` or
     * `full`, and a full-bleed image (whose HEIGHT only `full` matches) still
     * finds `full` on the same pass. Ties are broken by this list and by
     * nothing else, which is what makes the output deterministic — and
     * determinism is what makes the decompilation idempotent.
     *
     * @var list<PlacementArea>
     */
    private const array AREA_PREFERENCE = [
        PlacementArea::Top,
        PlacementArea::Middle,
        PlacementArea::Bottom,
        PlacementArea::Upper,
        PlacementArea::Lower,
        PlacementArea::Full,
    ];

    /**
     * Fabric's own defaults for the text properties DSL v1 carries. Reading a
     * missing key as its Fabric default is not a guess — it is what the canvas
     * renders — so a canvas that omits `fontSize` decompiles to `size: 40` and
     * round-trips exactly, with no loss reported.
     *
     * (`fontFamily` is the one that then fails at COMPILE time rather than
     * here: `Times New Roman` is almost certainly not one of the project's
     * faces, and plan §4.2-10 makes that a hard error. That is the compiler's
     * message to give, with the allowed list attached; inventing a substitute
     * here would only hide it.)
     */
    private const string FABRIC_DEFAULT_FONT_FAMILY = 'Times New Roman';
    private const float FABRIC_DEFAULT_FONT_SIZE = 40.0;
    private const float FABRIC_DEFAULT_LINE_HEIGHT = 1.16;

    /**
     * Read an entity. The decompiler is a READER — plan §4.5 invariant 20 is
     * about writes — so unlike {@see DesignCompiler} it may hold a
     * {@see TemplateVariant}; {@see decompile()} stays entity-free for the unit
     * tests and for callers holding raw arrays.
     */
    public function forVariant(TemplateVariant $variant, DecompilationContext $context): DecompiledDesign
    {
        /** @var mixed $decoded */
        $decoded = $variant->canvas === '' ? [] : json_decode($variant->canvas, true);

        return $this->decompile(
            is_array($decoded) ? $decoded : [],
            $variant->inputs,
            $variant->imageInputs,
            $variant->dimension->width(),
            $variant->dimension->height(),
            $variant->backgroundMode,
            $context,
        );
    }

    /**
     * @param array<array-key, mixed> $canvas the decoded canvas JSON document
     * @param array<EditorTextInput> $textInputs the variant's `inputs[]`, in
     *        their persisted order — which IS the positional binding order
     *        (plan §4.1 invariant 1)
     * @param array<EditorImageInput> $imageInputs the variant's `imageInputs[]`
     */
    public function decompile(
        array $canvas,
        array $textInputs,
        array $imageInputs,
        int $canvasWidth,
        int $canvasHeight,
        BackgroundMode $backgroundMode,
        DecompilationContext $context,
    ): DecompiledDesign {
        $textInputs = array_values($textInputs);
        $imageInputs = array_values($imageInputs);

        /** @var list<DesignLoss> $losses */
        $losses = [];

        $canvasSpec = new CanvasSpec(
            max(1, $canvasWidth),
            max(1, $canvasHeight),
            null,
            self::backgroundFill($canvas, $losses),
        );

        self::reportCanvasFeatures($canvas, $backgroundMode, $losses);

        $slots = self::readSlots($canvas, $textInputs, $losses);
        $slugs = self::assignSlugs($slots, $textInputs, $imageInputs, $losses);

        $elements = $this->buildElements($slots, $slugs, $textInputs, $imageInputs, $canvasSpec, $context, $losses);
        $inputIdsBySlug = self::identityMap($slots, $slugs, $textInputs, $losses);

        $elements = array_merge(
            $elements,
            self::buildContainers($canvas, $elements, $inputIdsBySlug, $slugs, $losses),
        );

        return new DecompiledDesign(
            new DesignDocument($canvasSpec, $elements),
            $inputIdsBySlug,
            $losses,
        );
    }

    // -----------------------------------------------------------------
    // document level
    // -----------------------------------------------------------------

    /**
     * Fabric serializes `Canvas.backgroundColor` under `background`; a gradient
     * or pattern there is an object, which the DSL has no word for.
     *
     * @param array<array-key, mixed> $canvas
     * @param list<DesignLoss> $losses
     */
    private static function backgroundFill(array $canvas, array &$losses): null|string
    {
        /** @var mixed $background */
        $background = $canvas['background'] ?? null;

        if ($background === null || $background === '') {
            return null;
        }

        if (!is_string($background)) {
            $losses[] = new DesignLoss(
                'canvas.background',
                DesignLossCode::CanvasFeatureDropped,
                'canvas.background is a gradient or pattern; the DSL carries only a flat canvas colour, so it is dropped.',
            );

            return null;
        }

        $normalized = self::normalizeColor($background);

        if ($normalized === null) {
            $losses[] = new DesignLoss(
                'canvas.background',
                DesignLossCode::CanvasFeatureDropped,
                sprintf('canvas.background ("%s") is not a plain colour the DSL can express, so it is dropped.', $background),
            );
        }

        return $normalized;
    }

    /**
     * Document-level features with no DSL surface.
     *
     * The canvas-mode background is the one NON-destructive entry in the whole
     * class: in {@see BackgroundMode::Canvas} the background is not in
     * `objects[]` at all — it lives on `template_variant.background_image` and
     * the renderer re-applies it — so a `set_design` that never mentions it
     * leaves it exactly where it was. It is reported anyway, because an agent
     * asked to "change the background" of such a variant has to be told that
     * this document is not where that happens.
     *
     * @param array<array-key, mixed> $canvas
     * @param list<DesignLoss> $losses
     */
    private static function reportCanvasFeatures(array $canvas, BackgroundMode $backgroundMode, array &$losses): void
    {
        if ($backgroundMode === BackgroundMode::Canvas) {
            $losses[] = new DesignLoss(
                'canvas.background',
                DesignLossCode::CanvasFeatureDropped,
                'This variant stores its background the legacy canvas-level way, not as a layer. The DSL has no element for it, so the design document neither shows nor changes it — the existing background is preserved untouched on the variant and re-applied when it renders.',
                destructive: false,
            );
        }

        /** @var mixed $guides */
        $guides = $canvas['guides'] ?? null;

        if (is_array($guides) && $guides !== []) {
            $losses[] = new DesignLoss(
                'canvas.guides',
                DesignLossCode::CanvasFeatureDropped,
                sprintf('The canvas carries %d ruler guide(s); the DSL has no word for them and they are dropped.', count($guides)),
            );
        }

        foreach (['overlayImage', 'clipPath', 'backgroundImage'] as $key) {
            /** @var mixed $value */
            $value = $canvas[$key] ?? null;

            if ($value === null || $value === []) {
                continue;
            }

            if ($key === 'backgroundImage' && $backgroundMode === BackgroundMode::Canvas) {
                // Not a second finding: this IS the canvas-level background the
                // note above describes, serialized into the document by Fabric.
                // The renderer re-applies it from `template_variant.background_image`
                // on every load, so dropping the serialized copy loses nothing.
                continue;
            }

            $losses[] = new DesignLoss(
                'canvas.' . $key,
                DesignLossCode::CanvasFeatureDropped,
                sprintf('The canvas carries a "%s"; the DSL has no word for it and it is dropped.', $key),
            );
        }
    }

    // -----------------------------------------------------------------
    // objects → slots
    // -----------------------------------------------------------------

    /**
     * One entry per canvas object that has a DSL element, in stack order.
     *
     * The walk also performs the POSITIONAL TEXTBOX BINDING (plan §4.1-1)
     * exactly as {@see \WBoost\Web\Services\SocialNetwork\TextInputObjectBinder}
     * does — the i-th VISIBLE `Textbox` is `inputs[i]` — because getting that
     * counter wrong here is the one mistake that produces a document which
     * looks right, compiles cleanly and puts every text under the wrong label.
     * Note the counter advances for every visible textbox, including ones with
     * no matching input, for the same reason the binder's does.
     *
     * @param array<array-key, mixed> $canvas
     * @param list<EditorTextInput> $textInputs
     * @param list<DesignLoss> $losses
     * @return list<array{index: int, object: array<array-key, mixed>, kind: 'text'|'image'|'shape'|'background', textInputIndex: null|int}>
     */
    private static function readSlots(array $canvas, array $textInputs, array &$losses): array
    {
        /** @var mixed $objects */
        $objects = $canvas['objects'] ?? null;

        if (!is_array($objects)) {
            return [];
        }

        /** @var list<array{index: int, object: array<array-key, mixed>, kind: 'text'|'image'|'shape'|'background', textInputIndex: null|int}> $slots */
        $slots = [];
        $position = 0;
        $textboxIndex = 0;
        $backgroundSeen = false;

        foreach ($objects as $object) {
            $index = $position;
            $position++;
            $path = sprintf('canvas.objects[%d]', $index);

            if (!is_array($object)) {
                $losses[] = new DesignLoss($path, DesignLossCode::ObjectDropped, sprintf(
                    '%s is not an object and is dropped.',
                    $path,
                ));

                continue;
            }

            $type = strtolower(self::string($object, 'type') ?? '');
            $isBackground = ($object['isBackground'] ?? false) === true;

            if (($object['visible'] ?? true) === false) {
                $losses[] = new DesignLoss($path, DesignLossCode::ObjectDropped, sprintf(
                    '%s is a design-hidden layer (the editor\'s per-layer eye). The DSL has no way to author a hidden object, so it is not in the document and saving would DELETE it — including its designed content.',
                    $path,
                ));

                continue;
            }

            if ($type === 'textbox') {
                $slots[] = [
                    'index' => $index,
                    'object' => $object,
                    'kind' => 'text',
                    'textInputIndex' => isset($textInputs[$textboxIndex]) ? $textboxIndex : null,
                ];
                $textboxIndex++;

                continue;
            }

            if ($type === 'image') {
                if ($isBackground && $backgroundSeen) {
                    $losses[] = new DesignLoss($path, DesignLossCode::ObjectDropped, sprintf(
                        '%s is a SECOND background layer; a design may have only one (it is the object at the bottom of the stack), so this one is dropped.',
                        $path,
                    ));

                    continue;
                }

                if ($isBackground) {
                    $backgroundSeen = true;

                    if ($index !== 0) {
                        $losses[] = new DesignLoss($path, DesignLossCode::ObjectRestacked, sprintf(
                            '%s is the background layer but sits at stack index %d; compiling pins the background to index 0, so the %d object(s) below it move up one place.',
                            $path,
                            $index,
                            $index,
                        ));
                    }
                }

                $slots[] = [
                    'index' => $index,
                    'object' => $object,
                    'kind' => $isBackground ? 'background' : 'image',
                    'textInputIndex' => null,
                ];

                continue;
            }

            if (CanvasShape::isShapeType($type)) {
                $slots[] = [
                    'index' => $index,
                    'object' => $object,
                    'kind' => 'shape',
                    'textInputIndex' => null,
                ];

                continue;
            }

            $losses[] = new DesignLoss($path, DesignLossCode::ObjectDropped, sprintf(
                '%s is a "%s" object. The DSL has element kinds for text, images, shapes and the background only, so it is not in the document and saving would DELETE it.',
                $path,
                self::string($object, 'type') ?? 'unknown',
            ));
        }

        $visibleTextboxes = $textboxIndex;

        for ($index = $visibleTextboxes; $index < count($textInputs); $index++) {
            $losses[] = new DesignLoss(sprintf('inputs[%d]', $index), DesignLossCode::ObjectDropped, sprintf(
                'inputs[%d] ("%s") has no visible textbox on the canvas to bind to (the canvas has %d, the variant has %d inputs), so the DSL has no element to carry it and it is dropped.',
                $index,
                $textInputs[$index]->name ?? '',
                $visibleTextboxes,
                count($textInputs),
            ));
        }

        return $slots;
    }

    // -----------------------------------------------------------------
    // slugs (see the class note — this is the identity-critical part)
    // -----------------------------------------------------------------

    /**
     * `slot position → slug`, named through {@see DesignSlug} in
     * {@see DesignIdentity::fromInputs()}'s walk order.
     *
     * @param list<array{index: int, object: array<array-key, mixed>, kind: 'text'|'image'|'shape'|'background', textInputIndex: null|int}> $slots
     * @param list<EditorTextInput> $textInputs
     * @param list<EditorImageInput> $imageInputs
     * @param list<DesignLoss> $losses
     * @return array<int, string> keyed by position in `$slots`
     */
    private static function assignSlugs(array $slots, array $textInputs, array $imageInputs, array &$losses): array
    {
        /** @var array<string, true> $taken */
        $taken = [];
        /** @var array<int, string> $byTextInputIndex */
        $byTextInputIndex = [];
        /** @var array<string, string> $byImageInputId */
        $byImageInputId = [];

        foreach ($textInputs as $index => $input) {
            $slug = DesignSlug::unique(DesignSlug::fromName($input->name, 'text'), $taken);
            $taken[$slug] = true;
            $byTextInputIndex[$index] = $slug;
        }

        foreach ($imageInputs as $input) {
            $slug = DesignSlug::unique(
                DesignSlug::fromName($input->name, $input->isBackground ? 'background' : 'image'),
                $taken,
            );
            $taken[$slug] = true;
            $byImageInputId[$input->inputId] = $slug;
        }

        /** @var array<int, string> $bySlot */
        $bySlot = [];
        /** @var array<string, true> $claimedImageInputIds */
        $claimedImageInputIds = [];

        foreach ($slots as $position => $slot) {
            $textInputIndex = $slot['textInputIndex'];

            if ($textInputIndex !== null) {
                $bySlot[$position] = $byTextInputIndex[$textInputIndex];

                continue;
            }

            if ($slot['kind'] === 'text') {
                continue; // no input entry — named in the leftover pass below
            }

            $inputId = self::string($slot['object'], 'inputId');

            if ($inputId !== null && isset($byImageInputId[$inputId]) && !isset($claimedImageInputIds[$inputId])) {
                $claimedImageInputIds[$inputId] = true;
                $bySlot[$position] = $byImageInputId[$inputId];
            }
        }

        foreach ($imageInputs as $index => $input) {
            if (!isset($claimedImageInputIds[$input->inputId])) {
                $losses[] = new DesignLoss(sprintf('imageInputs[%d]', $index), DesignLossCode::ObjectDropped, sprintf(
                    'imageInputs[%d] ("%s") names an inputId no visible image object on the canvas carries, so the DSL has no element to carry it and it is dropped.',
                    $index,
                    $input->name ?? '',
                ));
            }
        }

        foreach ($slots as $position => $slot) {
            if (isset($bySlot[$position])) {
                continue;
            }

            $fallback = match ($slot['kind']) {
                'text' => 'text',
                'background' => 'background',
                'image' => 'image',
                // The KIND, not the word "shape": a canvas of unnamed blocks
                // decompiles to `rectangle`, `rectangle-2`, `circle` rather
                // than `shape`, `shape-2`, `shape-3`, which is the difference
                // between a document an agent can edit and one it has to
                // re-read the geometry of.
                'shape' => (CanvasShapeKind::fromCanvasObject($slot['object']) ?? CanvasShapeKind::Rectangle)->value,
            };

            $slug = DesignSlug::unique(
                DesignSlug::fromName(self::string($slot['object'], 'name'), $fallback),
                $taken,
            );
            $taken[$slug] = true;
            $bySlot[$position] = $slug;
        }

        return $bySlot;
    }

    /**
     * `slug → inputId`, refusing to hand one UUID to two slugs — the same rule
     * {@see DesignCompiler::assignInputIds()} enforces on the way back, stated
     * here so the agent is TOLD which element is about to lose its identity
     * rather than discovering it in a broken fill.
     *
     * @param list<array{index: int, object: array<array-key, mixed>, kind: 'text'|'image'|'shape'|'background', textInputIndex: null|int}> $slots
     * @param array<int, string> $slugs
     * @param list<EditorTextInput> $textInputs
     * @param list<DesignLoss> $losses
     * @return array<string, string>
     */
    private static function identityMap(
        array $slots,
        array $slugs,
        array $textInputs,
        array &$losses,
    ): array {
        /** @var array<string, string> $map */
        $map = [];
        /** @var array<string, string> $owner inputId => the slug that claimed it */
        $owner = [];

        foreach ($slots as $position => $slot) {
            $slug = $slugs[$position] ?? null;

            if ($slug === null) {
                continue;
            }

            $textInputIndex = $slot['textInputIndex'];
            $objectInputId = self::string($slot['object'], 'inputId');

            // Texts take their id from inputs[] (the object's own is
            // unreliable post-v7-migration — see TextInputObjectBinder);
            // images take theirs from the object, which is where image
            // placeholders have always bound.
            $inputId = $textInputIndex !== null
                ? $textInputs[$textInputIndex]->inputId
                : $objectInputId;

            if ($inputId === null || $inputId === '') {
                continue;
            }

            if (isset($owner[$inputId])) {
                $losses[] = new DesignLoss(
                    sprintf('canvas.objects[%d].inputId', $slot['index']),
                    DesignLossCode::IdentityRemapped,
                    sprintf(
                        'canvas.objects[%d] shares its inputId with "%s"; one id cannot address two elements, so "%s" gets a fresh one when the design is saved and anything keyed on the old id (saved fills, API consumers, container membership) stops resolving for it.',
                        $slot['index'],
                        $owner[$inputId],
                        $slug,
                    ),
                );

                continue;
            }

            $owner[$inputId] = $slug;
            $map[$slug] = $inputId;
        }

        return $map;
    }

    // -----------------------------------------------------------------
    // elements
    // -----------------------------------------------------------------

    /**
     * The drawable elements, background first (the compiler pins it to stack
     * index 0 regardless of where it was stored, so the document says so too).
     *
     * @param list<array{index: int, object: array<array-key, mixed>, kind: 'text'|'image'|'shape'|'background', textInputIndex: null|int}> $slots
     * @param array<int, string> $slugs
     * @param list<EditorTextInput> $textInputs
     * @param list<EditorImageInput> $imageInputs
     * @param list<DesignLoss> $losses
     * @return list<DesignElement>
     */
    private function buildElements(
        array $slots,
        array $slugs,
        array $textInputs,
        array $imageInputs,
        CanvasSpec $canvas,
        DecompilationContext $context,
        array &$losses,
    ): array {
        /** @var array<string, EditorImageInput> $imageInputsById */
        $imageInputsById = [];

        foreach ($imageInputs as $input) {
            $imageInputsById[$input->inputId] ??= $input;
        }

        // The document's element order IS the stack order, and the compiler
        // pins the background to index 0 wherever the canvas kept it — so the
        // path an element is reported under has to be its position in the
        // EMITTED document, not in the walk.
        $documentIndexes = self::documentIndexes($slots);

        /** @var list<DesignElement> $background */
        $background = [];
        /** @var list<DesignElement> $rest */
        $rest = [];

        foreach ($slots as $position => $slot) {
            $slug = $slugs[$position] ?? null;

            if ($slug === null) {
                continue;
            }

            $object = $slot['object'];
            $path = sprintf('elements[%d]', $documentIndexes[$position]);

            if ($slot['kind'] === 'background') {
                $background[] = $this->backgroundElement($slug, $object, $path, $canvas, $context, $losses);

                continue;
            }

            self::reportSharedTransforms($path, $object, $losses);
            // A shape words its own opacity, stroke and editor lock.
            $isShape = $slot['kind'] === 'shape';
            self::reportSharedStyles(
                $path,
                $object,
                $losses,
                allowEditorLock: $isShape,
                allowOpacity: $isShape,
                allowStroke: $isShape,
            );

            if ($slot['kind'] === 'text') {
                $rest[] = self::textElement(
                    $slug,
                    $object,
                    $path,
                    $slot['textInputIndex'] === null ? null : $textInputs[$slot['textInputIndex']],
                    $canvas,
                    $losses,
                );

                continue;
            }

            if ($slot['kind'] === 'shape') {
                $rest[] = self::shapeElement($slug, $object, $path, $canvas, $losses);

                continue;
            }

            $inputId = self::string($object, 'inputId');
            $rest[] = self::imageElement(
                $slug,
                $object,
                $path,
                $inputId === null ? null : ($imageInputsById[$inputId] ?? null),
                $canvas,
                $context,
                $losses,
            );
        }

        return array_merge($background, $rest);
    }

    /**
     * `slot position → index in the emitted elements[]`, with the background
     * hoisted to 0.
     *
     * @param list<array{index: int, object: array<array-key, mixed>, kind: 'text'|'image'|'shape'|'background', textInputIndex: null|int}> $slots
     * @return array<int, int>
     */
    private static function documentIndexes(array $slots): array
    {
        $hasBackground = false;

        foreach ($slots as $slot) {
            if ($slot['kind'] === 'background') {
                $hasBackground = true;

                break;
            }
        }

        /** @var array<int, int> $indexes */
        $indexes = [];
        $next = $hasBackground ? 1 : 0;

        foreach ($slots as $position => $slot) {
            if ($slot['kind'] === 'background') {
                $indexes[$position] = 0;

                continue;
            }

            $indexes[$position] = $next;
            $next++;
        }

        return $indexes;
    }

    /**
     * The background layer.
     *
     * Its geometry is NOT authored — {@see \WBoost\Web\Services\Editor\BackgroundLayer}
     * owns the cover transform and the compiler calls it — so the only thing
     * to check is whether the stored object still IS that cover fit. A designer
     * who unlocked the layer and moved or scaled it has a background this
     * document cannot describe, and saving would snap it back; that is worth a
     * sentence, and the natural size from {@see DecompilationContext} is what
     * makes it provable rather than guessed.
     *
     * @param array<array-key, mixed> $object
     * @param list<DesignLoss> $losses
     */
    private function backgroundElement(
        string $slug,
        array $object,
        string $path,
        CanvasSpec $canvas,
        DecompilationContext $context,
        array &$losses,
    ): BackgroundElement {
        self::reportSharedTransforms($path, $object, $losses);
        self::reportSharedStyles($path, $object, $losses, allowEditorLock: true);

        $assetPath = self::string($object, 'assetPath');
        $asset = $context->forObject($assetPath, self::string($object, 'src'));
        $assetId = $asset === null ? self::galleryId($object) : $asset->id;
        $fillable = ($object['imagePlaceholder'] ?? false) === true;

        if ($assetId === null) {
            $losses[] = new DesignLoss($path . '.asset', DesignLossCode::AssetUnresolved, sprintf(
                '%s.asset: the background picture (%s) is not a gallery image, so the DSL — which addresses pictures by gallery id — cannot name it. Saving this design leaves the variant with NO background; upload the picture to the gallery first and reference it by id.',
                $path,
                $assetPath === null ? 'no stored path' : '"' . $assetPath . '"',
            ));

            // A fillable background with no picture is the one combination the
            // grammar allows and the compiler refuses (there would be no
            // object to carry the flag, and an unfilled slot must render the
            // designed picture). Emitting it would hand the agent a document
            // that cannot compile, which helps nobody.
            $fillable = false;
        }

        $left = self::number($object, 'left') ?? 0.0;
        $top = self::number($object, 'top') ?? 0.0;
        $scaleX = self::number($object, 'scaleX') ?? 1.0;
        $scaleY = self::number($object, 'scaleY') ?? 1.0;

        $offGrid = abs($left) > self::GEOMETRY_EPSILON || abs($top) > self::GEOMETRY_EPSILON;

        if (!$offGrid && $asset !== null && $asset->hasNaturalSize()) {
            assert($asset->width !== null && $asset->height !== null);
            $cover = max($canvas->width / $asset->width, $canvas->height / $asset->height);
            $offGrid = abs($scaleX - $cover) > 1.0e-4 || abs($scaleY - $cover) > 1.0e-4;
        }

        if ($offGrid) {
            $losses[] = new DesignLoss($path, DesignLossCode::TransformDropped, sprintf(
                '%s: the background layer has been moved or resized away from the cover fit. The DSL has no geometry for a background (it is always the least scale that covers the canvas, anchored top-left), so saving snaps it back.',
                $path,
            ));
        }

        return new BackgroundElement($slug, $assetId, $fillable);
    }

    /**
     * @param array<array-key, mixed> $object
     * @param list<DesignLoss> $losses
     */
    private static function textElement(
        string $slug,
        array $object,
        string $path,
        null|EditorTextInput $input,
        CanvasSpec $canvas,
        array &$losses,
    ): TextElement {
        $scaleX = self::number($object, 'scaleX') ?? 1.0;
        $scaleY = self::number($object, 'scaleY') ?? 1.0;

        if (abs($scaleX - $scaleY) > self::GEOMETRY_EPSILON) {
            $losses[] = new DesignLoss($path, DesignLossCode::TransformDropped, sprintf(
                '%s: the textbox is scaled non-uniformly (%s x %s). The DSL folds a textbox\'s scale into its width and font size, which can only be done uniformly, so the glyphs change shape.',
                $path,
                self::formatNumber($scaleX),
                self::formatNumber($scaleY),
            ));
        }

        self::reportTextStyles($path, $object, $losses);
        self::reportInputFeatures($path, $object, $input, $losses);

        // A scaled textbox is flattened, not carried: `width` is the WRAP width
        // (a scale widens the wrap) and `fontSize` scales with it, so the same
        // pixels come out at scale 1 — which is the only scale the DSL knows.
        $width = self::positive((self::number($object, 'width') ?? 0.0) * $scaleX, 1.0);
        $size = self::positive((self::number($object, 'fontSize') ?? self::FABRIC_DEFAULT_FONT_SIZE) * $scaleY, self::FABRIC_DEFAULT_FONT_SIZE);

        $placement = self::placement(
            self::number($object, 'left') ?? 0.0,
            self::number($object, 'top') ?? 0.0,
            $width,
            null,
            $canvas,
        );

        $font = self::string($object, 'fontFamily');
        $align = TextAlign::tryFrom(strtolower(self::string($object, 'textAlign') ?? 'left'));

        if ($align === null) {
            $losses[] = new DesignLoss($path . '.align', DesignLossCode::StyleDropped, sprintf(
                '%s.align: "%s" is not one of the DSL\'s alignments (%s); it becomes "left".',
                $path,
                self::string($object, 'textAlign') ?? '',
                implode(', ', TextAlign::values()),
            ));
        }

        return new TextElement(
            $slug,
            self::string($object, 'text') ?? '',
            $font === null || trim($font) === '' ? self::FABRIC_DEFAULT_FONT_FAMILY : $font,
            $size,
            self::textColor($object, $path, $losses),
            $align ?? TextAlign::Left,
            self::positive(self::number($object, 'lineHeight') ?? self::FABRIC_DEFAULT_LINE_HEIGHT, self::FABRIC_DEFAULT_LINE_HEIGHT),
            $placement,
            self::textInputSpec($object, $input),
        );
    }

    /**
     * @param array<array-key, mixed> $object
     * @param list<DesignLoss> $losses
     */
    private static function imageElement(
        string $slug,
        array $object,
        string $path,
        null|EditorImageInput $input,
        CanvasSpec $canvas,
        DecompilationContext $context,
        array &$losses,
    ): ImageElement {
        $scaleX = self::number($object, 'scaleX') ?? 1.0;
        $scaleY = self::number($object, 'scaleY') ?? 1.0;
        $placeholder = ($object['imagePlaceholder'] ?? false) === true;

        // A fillable placeholder fills its rect exactly, so any scale pair
        // round-trips; a decorative image is CONTAIN-fitted (uniform), so a
        // non-uniform one is squeezed back into shape.
        if (!$placeholder && abs($scaleX - $scaleY) > self::GEOMETRY_EPSILON) {
            $losses[] = new DesignLoss($path, DesignLossCode::TransformDropped, sprintf(
                '%s: the decorative image is stretched non-uniformly (%s x %s). The DSL contain-fits a decorative picture into its rect without distorting it, so saving restores its aspect ratio.',
                $path,
                self::formatNumber($scaleX),
                self::formatNumber($scaleY),
            ));
        }

        self::reportImageStyles($path, $object, $losses);

        $assetPath = self::string($object, 'assetPath');
        $asset = $context->forObject($assetPath, self::string($object, 'src'));
        $assetId = $asset === null ? self::galleryId($object) : $asset->id;

        if ($assetId === null && self::showsAPicture($object)) {
            $losses[] = new DesignLoss($path . '.asset', DesignLossCode::AssetUnresolved, sprintf(
                '%s.asset: the picture (%s) is not a gallery image, so the DSL — which addresses pictures by gallery id — cannot name it. Saving leaves this element in place with no picture.',
                $path,
                $assetPath === null ? 'no stored path' : '"' . $assetPath . '"',
            ));
        }

        $placement = self::placement(
            self::number($object, 'left') ?? 0.0,
            self::number($object, 'top') ?? 0.0,
            self::positive((self::number($object, 'width') ?? 0.0) * $scaleX, 1.0),
            self::positive((self::number($object, 'height') ?? 0.0) * $scaleY, 1.0),
            $canvas,
        );

        return new ImageElement($slug, $assetId, $placement, self::imageInputSpec($object, $input, $placeholder, $path, $losses));
    }

    /**
     * A vector shape → {@see ShapeElement}.
     *
     * Geometry is read as the DISPLAYED box (`width × scaleX`), which is what
     * the compiler re-emits at scale 1 — the same flattening
     * {@see self::textElement()} does for a scaled textbox, and what makes a
     * shape the designer dragged a corner handle on round-trip to the size it
     * actually is on screen rather than to its base dimensions.
     *
     * `strokeWidth` is NOT scale-corrected on purpose: shapes are authored
     * `strokeUniform`, so the stored number already is the on-screen weight.
     *
     * @param array<array-key, mixed> $object
     * @param list<DesignLoss> $losses
     */
    private static function shapeElement(
        string $slug,
        array $object,
        string $path,
        CanvasSpec $canvas,
        array &$losses,
    ): ShapeElement {
        $scaleX = self::number($object, 'scaleX') ?? 1.0;
        $scaleY = self::number($object, 'scaleY') ?? 1.0;
        $kind = CanvasShapeKind::fromCanvasObject($object) ?? CanvasShapeKind::Rectangle;

        $width = self::positive((self::number($object, 'width') ?? 0.0) * $scaleX, 1.0);
        $height = self::positive((self::number($object, 'height') ?? 0.0) * $scaleY, 1.0);

        /** @var mixed $rawFill */
        $rawFill = $object['fill'] ?? null;
        $gradient = CanvasShapeGradient::fromFabric($rawFill);
        $fill = ShapeElement::DEFAULT_FILL;

        if ($gradient !== null) {
            $fill = $gradient;
        } elseif (is_string($rawFill)) {
            $fill = self::normalizeColor($rawFill) ?? ShapeElement::DEFAULT_FILL;
        } elseif ($rawFill !== null) {
            // A pattern fill, or a gradient too exotic to name. The shape keeps
            // its geometry; only the paint is lost.
            $losses[] = new DesignLoss($path . '.fill', DesignLossCode::StyleDropped, sprintf(
                '%s.fill is a fill the DSL cannot name (it words flat colours and two-stop gradients only), so saving repaints the shape %s.',
                $path,
                ShapeElement::DEFAULT_FILL,
            ));
        }

        $strokeWidth = self::number($object, 'strokeWidth') ?? 0.0;
        $rawStroke = self::string($object, 'stroke');
        $stroke = $rawStroke === null ? null : self::normalizeColor($rawStroke);

        // Corner radius lives in `rx`/`ry` — but on an Ellipse those very keys
        // are the radii, i.e. the SIZE, which `width`/`height` already carry.
        $cornerRadius = $kind->supportsCornerRadius()
            ? max(0.0, self::number($object, 'rx') ?? 0.0)
            : 0.0;

        return new ShapeElement(
            id: $slug,
            shape: $kind,
            fill: $fill,
            stroke: $stroke,
            strokeWidth: max(0.0, $strokeWidth),
            strokeStyle: CanvasShapeStroke::fromDashArray($object['strokeDashArray'] ?? null),
            cornerRadius: $cornerRadius,
            opacity: min(1.0, max(0.0, self::number($object, 'opacity') ?? 1.0)),
            name: self::string($object, 'name'),
            locked: ($object['editorLocked'] ?? false) === true,
            placement: self::placement(
                self::number($object, 'left') ?? 0.0,
                self::number($object, 'top') ?? 0.0,
                $width,
                $height,
                $canvas,
            ),
        );
    }

    // -----------------------------------------------------------------
    // input blocks
    // -----------------------------------------------------------------

    /**
     * The seven text-input keys. `inputs[]` wins where it exists — it is what
     * the renderer resolves overrides against — and the canvas object's mirror
     * of the same properties is the fallback for a textbox the array does not
     * reach.
     *
     * @param array<array-key, mixed> $object
     */
    private static function textInputSpec(array $object, null|EditorTextInput $input): TextInputSpec
    {
        if ($input !== null) {
            return new TextInputSpec(
                self::blankToNull($input->name),
                $input->maxLength !== null && $input->maxLength >= 1 ? $input->maxLength : null,
                $input->uppercase,
                $input->hidable,
                $input->locked,
                $input->richText,
                self::blankToNull($input->sampleValue),
                $input->allowedFonts,
            );
        }

        $maxLength = self::integer($object, 'maxLength');

        return new TextInputSpec(
            self::blankToNull(self::string($object, 'name')),
            $maxLength !== null && $maxLength >= 1 ? $maxLength : null,
            ($object['uppercase'] ?? false) === true,
            ($object['hidable'] ?? false) === true,
            ($object['locked'] ?? false) === true,
            ($object['richText'] ?? false) === true,
            self::blankToNull(self::string($object, 'sampleValue')),
            self::stringList($object, 'allowedFonts'),
        );
    }

    /**
     * @param array<array-key, mixed> $object
     * @return list<string>
     */
    private static function stringList(array $object, string $key): array
    {
        $raw = $object[$key] ?? null;
        $values = [];

        if (is_array($raw)) {
            foreach ($raw as $value) {
                if (is_string($value) && trim($value) !== '' && !in_array($value, $values, true)) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * The image `input` block, or null for a nameless decorative picture.
     *
     * A decorative image the designer NAMED still gets a block — with
     * `placeholder: false`, the DSL's explicit opt-out — because the name is
     * designer metadata the canvas carries and dropping it would lose it.
     *
     * @param array<array-key, mixed> $object
     * @param list<DesignLoss> $losses
     */
    private static function imageInputSpec(
        array $object,
        null|EditorImageInput $input,
        bool $placeholder,
        string $path,
        array &$losses,
    ): null|ImageInputSpec {
        if ($input !== null) {
            self::reportImageInputFeatures($path, $input, $losses);

            return new ImageInputSpec(
                self::blankToNull($input->name),
                $placeholder,
                $input->allowMove,
                $input->allowResize,
                $input->allowRotate,
                $input->hidable,
                self::directoryIds($input->allowedDirectoryIds, $path, $losses),
            );
        }

        $name = self::blankToNull(self::string($object, 'name'));

        if (!$placeholder && $name === null) {
            return null;
        }

        /** @var mixed $rawDirectories */
        $rawDirectories = $object['allowedDirectoryIds'] ?? null;
        /** @var list<string> $directories */
        $directories = [];

        if (is_array($rawDirectories)) {
            foreach ($rawDirectories as $value) {
                if (is_string($value)) {
                    $directories[] = $value;
                }
            }
        }

        return new ImageInputSpec(
            $name,
            $placeholder,
            ($object['allowMove'] ?? true) === true,
            ($object['allowResize'] ?? true) === true,
            ($object['allowRotate'] ?? false) === true,
            ($object['hidable'] ?? false) === true,
            self::directoryIds($directories, $path, $losses),
        );
    }

    /**
     * @param list<string> $ids
     * @param list<DesignLoss> $losses
     * @return list<string>
     */
    private static function directoryIds(array $ids, string $path, array &$losses): array
    {
        /** @var list<string> $valid */
        $valid = [];

        foreach ($ids as $id) {
            if (Uuid::isValid($id)) {
                $valid[] = $id;

                continue;
            }

            $losses[] = new DesignLoss($path . '.input.allowedDirectories', DesignLossCode::InputFeatureDropped, sprintf(
                '%s.input.allowedDirectories contains "%s", which is not a gallery folder id; it is dropped.',
                $path,
                $id,
            ));
        }

        return $valid;
    }

    // -----------------------------------------------------------------
    // placement (see the class note on semantic vs absolute)
    // -----------------------------------------------------------------

    /**
     * A semantic placement when one reproduces the rect EXACTLY, absolute
     * pixels otherwise.
     *
     * `$height` is null for a textbox: Fabric computes a textbox's height from
     * its wrapped content and the DSL has no key for it (plan §4.2-6), so
     * nothing about the height participates in the match.
     */
    private static function placement(float $x, float $y, float $width, null|float $height, CanvasSpec $canvas): Placement
    {
        foreach (self::semanticCandidates($x, $y, $width, $height, $canvas) as $candidate) {
            if (self::resolvesTo($candidate, $x, $y, $width, $height, $canvas)) {
                return $candidate;
            }
        }

        return new Placement(null, $x, $y, $width, $height);
    }

    /**
     * The `at` placements worth trying, best first: the band that supplies the
     * height too, then the band that supplies only the vertical position with
     * the height written out beside it.
     *
     * @return list<Placement>
     */
    private static function semanticCandidates(float $x, float $y, float $width, null|float $height, CanvasSpec $canvas): array
    {
        $columns = self::columnSpan($x, $width, $canvas);

        if ($columns === null) {
            return [];
        }

        /** @var list<Placement> $candidates */
        $candidates = [];

        foreach (self::AREA_PREFERENCE as $area) {
            [$bandTop, $bandBottom] = GridResolver::areaBand($area, $canvas->height);

            if (abs($bandTop - $y) > self::GEOMETRY_EPSILON) {
                continue;
            }

            $at = new SemanticPlacement($area, $columns[0], $columns[1]);

            if ($height === null || abs(($bandBottom - $bandTop) - $height) <= self::GEOMETRY_EPSILON) {
                $candidates[] = new Placement($at);

                continue;
            }

            $candidates[] = new Placement($at, height: $height);
        }

        return $candidates;
    }

    /**
     * The 1-based inclusive column pair whose grid edges are exactly this
     * element's left and right, or null when it does not sit on the grid.
     *
     * @return null|array{int, int}
     */
    private static function columnSpan(float $x, float $width, CanvasSpec $canvas): null|array
    {
        $edges = GridResolver::columnEdges($canvas->width);
        $start = null;
        $end = null;

        foreach ($edges as $index => $edge) {
            if ($start === null && abs($edge - $x) <= self::GEOMETRY_EPSILON) {
                $start = $index;
            }

            if ($start !== null && $index > $start && abs($edge - ($x + $width)) <= self::GEOMETRY_EPSILON) {
                $end = $index;

                break;
            }
        }

        if ($start === null || $end === null) {
            return null;
        }

        return [$start + 1, $end];
    }

    /**
     * The verification the class note promises: resolve the candidate through
     * the very {@see GridResolver} the compiler will use and compare.
     */
    private static function resolvesTo(Placement $candidate, float $x, float $y, float $width, null|float $height, CanvasSpec $canvas): bool
    {
        $rect = GridResolver::resolvePlacement($candidate, $canvas);

        if (
            abs($rect->x - $x) > self::GEOMETRY_EPSILON
            || abs($rect->y - $y) > self::GEOMETRY_EPSILON
            || abs($rect->width - $width) > self::GEOMETRY_EPSILON
        ) {
            return false;
        }

        if ($height === null) {
            return true;
        }

        return $rect->height !== null && abs($rect->height - $height) <= self::GEOMETRY_EPSILON;
    }

    // -----------------------------------------------------------------
    // containers
    // -----------------------------------------------------------------

    /**
     * The `containers` key as {@see ContainerElement}s, sanitized to what
     * {@see DslParser} will accept.
     *
     * The sanitization is not defensive politeness: the round trip re-PARSES
     * this document, and the parser rejects a member that is a fillable
     * placeholder, a member no element declares, a child with two parents, a
     * cycle, and a container holding fewer than two items. Emitting any of
     * them would turn "this design contains something the DSL cannot express"
     * into "get_design produced a document that will not parse", which is a
     * far worse failure. Each drop is reported instead.
     *
     * @param array<array-key, mixed> $canvas
     * @param list<DesignElement> $elements
     * @param array<string, string> $inputIdsBySlug
     * @param array<int, string> $slugs
     * @param list<DesignLoss> $losses
     * @return list<ContainerElement>
     */
    private static function buildContainers(
        array $canvas,
        array $elements,
        array $inputIdsBySlug,
        array $slugs,
        array &$losses,
    ): array {
        $containers = CanvasContainer::collectionFromCanvas($canvas);

        if ($containers === []) {
            return [];
        }

        /** @var array<string, string> $slugByInputId */
        $slugByInputId = [];

        foreach ($inputIdsBySlug as $slug => $inputId) {
            $slugByInputId[$inputId] ??= $slug;
        }

        /** @var array<string, DesignElement> $elementBySlug */
        $elementBySlug = [];

        foreach ($elements as $element) {
            $elementBySlug[$element->id] = $element;
        }

        /** @var array<string, true> $taken */
        $taken = [];

        foreach (array_values($slugs) as $slug) {
            $taken[$slug] = true;
        }

        /** @var array<string, string> $idBySourceId */
        $idBySourceId = [];

        foreach ($containers as $container) {
            $candidate = preg_match(DslParser::SLUG_PATTERN, $container->id) === 1
                && strlen($container->id) <= DslParser::MAX_SLUG_LENGTH
                    ? $container->id
                    : DesignSlug::fromName($container->id, 'container');

            $slug = DesignSlug::unique($candidate, $taken);
            $taken[$slug] = true;
            $idBySourceId[$container->id] = $slug;

            if ($slug !== $container->id) {
                $losses[] = new DesignLoss(
                    'canvas.containers',
                    DesignLossCode::IdentityRemapped,
                    sprintf(
                        'The container id "%s" is not a DSL slug (or collides with an element id) and becomes "%s"; anything quoting the old id — a strict-export container_overflow response, for instance — stops matching.',
                        $container->id,
                        $slug,
                    ),
                );
            }
        }

        /** @var list<array{id: string, members: list<string>, children: list<string>, maxHeight: null|float, gap: null|float, spaceAfter: null|float}> $built */
        $built = [];

        foreach ($containers as $position => $container) {
            $path = sprintf('canvas.containers[%d]', $position);
            /** @var list<string> $members */
            $members = [];

            foreach ($container->memberInputIds as $memberInputId) {
                $slug = $slugByInputId[$memberInputId] ?? null;
                $element = $slug === null ? null : ($elementBySlug[$slug] ?? null);

                if ($slug === null || $element === null) {
                    $losses[] = new DesignLoss($path, DesignLossCode::InputFeatureDropped, sprintf(
                        '%s flows a member (%s) that no element in this design carries; the membership is dropped.',
                        $path,
                        $memberInputId,
                    ));

                    continue;
                }

                if ($element instanceof BackgroundElement || ($element instanceof ImageElement && $element->isPlaceholder())) {
                    $losses[] = new DesignLoss($path, DesignLossCode::InputFeatureDropped, sprintf(
                        '%s flows "%s", which is %s. Only texts and decorative images can be container members, so the membership is dropped.',
                        $path,
                        $slug,
                        $element instanceof BackgroundElement ? 'the background layer' : 'a fillable image placeholder',
                    ));

                    continue;
                }

                $members[] = $slug;
            }

            /** @var list<string> $children */
            $children = [];

            foreach ($container->memberContainerIds as $childId) {
                $childSlug = $idBySourceId[$childId] ?? null;

                if ($childSlug === null) {
                    $losses[] = new DesignLoss($path, DesignLossCode::InputFeatureDropped, sprintf(
                        '%s nests a container (%s) this canvas does not define; the nesting is dropped.',
                        $path,
                        $childId,
                    ));

                    continue;
                }

                $children[] = $childSlug;
            }

            $built[] = [
                'id' => $idBySourceId[$container->id],
                'members' => array_values(array_unique($members)),
                'children' => array_values(array_unique($children)),
                'maxHeight' => $container->maxHeight,
                'gap' => $container->gap,
                'spaceAfter' => $container->spaceAfter,
            ];
        }

        $built = self::pruneContainerChildren($built, $losses);
        $built = self::dropDegenerateContainers($built, $losses);

        return array_map(
            static fn (array $entry): ContainerElement => new ContainerElement(
                $entry['id'],
                $entry['members'],
                $entry['children'],
                $entry['maxHeight'],
                $entry['gap'],
                $entry['spaceAfter'],
            ),
            $built,
        );
    }

    /**
     * One parent per child (first claimant in list order) and no cycles —
     * mirroring `sanitizedContainers()` in `assets/controllers/canvas_payload.js`
     * and {@see DesignCompiler}'s own pass, because the parser enforces both.
     *
     * @param list<array{id: string, members: list<string>, children: list<string>, maxHeight: null|float, gap: null|float, spaceAfter: null|float}> $containers
     * @param list<DesignLoss> $losses
     * @return list<array{id: string, members: list<string>, children: list<string>, maxHeight: null|float, gap: null|float, spaceAfter: null|float}>
     */
    private static function pruneContainerChildren(array $containers, array &$losses): array
    {
        /** @var array<string, true> $claimed */
        $claimed = [];
        /** @var array<string, list<string>> $byId */
        $byId = [];

        foreach ($containers as $container) {
            $byId[$container['id']] = $container['children'];
        }

        foreach ($containers as $index => $container) {
            /** @var list<string> $children */
            $children = [];

            foreach ($container['children'] as $childId) {
                if ($childId === $container['id'] || isset($claimed[$childId]) || self::reaches($childId, $container['id'], $byId, [])) {
                    $losses[] = new DesignLoss(
                        'canvas.containers',
                        DesignLossCode::InputFeatureDropped,
                        sprintf(
                            'Container "%s" cannot nest "%s" (it would give the child a second parent, or close a cycle); the nesting is dropped.',
                            $container['id'],
                            $childId,
                        ),
                    );

                    continue;
                }

                $claimed[$childId] = true;
                $children[] = $childId;
            }

            $containers[$index]['children'] = $children;
            $byId[$container['id']] = $children;
        }

        return $containers;
    }

    /**
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
     * A container needs 2+ items counting nested children, and dropping one can
     * strip its parent below the minimum — hence the fixpoint, matching
     * {@see DesignCompiler::dropDegenerateToFixpoint()}.
     *
     * @param list<array{id: string, members: list<string>, children: list<string>, maxHeight: null|float, gap: null|float, spaceAfter: null|float}> $containers
     * @param list<DesignLoss> $losses
     * @return list<array{id: string, members: list<string>, children: list<string>, maxHeight: null|float, gap: null|float, spaceAfter: null|float}>
     */
    private static function dropDegenerateContainers(array $containers, array &$losses): array
    {
        for (;;) {
            /** @var array<string, true> $valid */
            $valid = [];

            foreach ($containers as $container) {
                if (count($container['members']) + count($container['children']) >= 2) {
                    $valid[$container['id']] = true;
                }
            }

            if (count($valid) === count($containers)) {
                return $containers;
            }

            /** @var list<array{id: string, members: list<string>, children: list<string>, maxHeight: null|float, gap: null|float, spaceAfter: null|float}> $next */
            $next = [];

            foreach ($containers as $container) {
                if (!isset($valid[$container['id']])) {
                    $losses[] = new DesignLoss(
                        'canvas.containers',
                        DesignLossCode::InputFeatureDropped,
                        sprintf(
                            'Container "%s" is left with fewer than 2 items and is dropped; the elements it grouped stay in the design but stop reflowing together.',
                            $container['id'],
                        ),
                    );

                    continue;
                }

                $container['children'] = array_values(array_filter(
                    $container['children'],
                    static fn (string $childId): bool => isset($valid[$childId]),
                ));

                $next[] = $container;
            }

            $containers = $next;
        }
    }

    // -----------------------------------------------------------------
    // loss detection
    // -----------------------------------------------------------------

    /**
     * Geometry every object kind shares and the DSL's axis-aligned rect cannot
     * carry.
     *
     * @param array<array-key, mixed> $object
     * @param list<DesignLoss> $losses
     */
    private static function reportSharedTransforms(string $path, array $object, array &$losses): void
    {
        $angle = self::number($object, 'angle') ?? 0.0;

        if (abs($angle) > self::GEOMETRY_EPSILON) {
            $losses[] = new DesignLoss($path, DesignLossCode::TransformDropped, sprintf(
                '%s is rotated %s degrees. The DSL places elements as axis-aligned rectangles, so saving straightens it — and its bounding box moves with it.',
                $path,
                self::formatNumber($angle),
            ));
        }

        foreach (['flipX', 'flipY'] as $key) {
            if (($object[$key] ?? false) === true) {
                $losses[] = new DesignLoss($path, DesignLossCode::TransformDropped, sprintf(
                    '%s is mirrored (%s); the DSL has no word for it and saving un-mirrors it.',
                    $path,
                    $key,
                ));
            }
        }

        foreach (['skewX', 'skewY'] as $key) {
            $skew = self::number($object, $key) ?? 0.0;

            if (abs($skew) > self::GEOMETRY_EPSILON) {
                $losses[] = new DesignLoss($path, DesignLossCode::TransformDropped, sprintf(
                    '%s is skewed (%s = %s); the DSL has no word for it and saving un-skews it.',
                    $path,
                    $key,
                    self::formatNumber($skew),
                ));
            }
        }
    }

    /**
     * Painting every object kind shares.
     *
     * `editorLocked` is included on purpose: it is a persisted
     * `CANVAS_CUSTOM_PROPERTY` and the compiler authors it only where
     * {@see \WBoost\Web\Services\Editor\BackgroundLayer} does, so a designer's
     * lock on any other object is lost — small, but exactly the kind of thing
     * that has to be said out loud rather than discovered.
     *
     * The three `$allow*` flags exist because a loss is a statement about the
     * GRAMMAR, not about the pixel: {@see ShapeElement} words opacity, stroke
     * and the editor lock, so reporting them on a shape would tell the agent it
     * is about to destroy something the very next `set_design` writes back
     * unchanged — and a loss report nobody can trust is worse than none.
     *
     * @param array<array-key, mixed> $object
     * @param list<DesignLoss> $losses
     */
    private static function reportSharedStyles(
        string $path,
        array $object,
        array &$losses,
        bool $allowEditorLock = false,
        bool $allowOpacity = false,
        bool $allowStroke = false,
    ): void {
        $opacity = self::number($object, 'opacity');

        if (!$allowOpacity && $opacity !== null && abs($opacity - 1.0) > 1.0e-4) {
            $losses[] = new DesignLoss($path, DesignLossCode::StyleDropped, sprintf(
                '%s has opacity %s; the DSL has no opacity and saving makes it fully opaque.',
                $path,
                self::formatNumber($opacity),
            ));
        }

        if (($object['shadow'] ?? null) !== null) {
            $losses[] = new DesignLoss($path, DesignLossCode::StyleDropped, sprintf(
                '%s has a drop shadow; the DSL has no word for it and saving removes it.',
                $path,
            ));
        }

        $stroke = self::string($object, 'stroke');

        if (!$allowStroke && $stroke !== null && trim($stroke) !== '' && (self::number($object, 'strokeWidth') ?? 0.0) > 0.0) {
            $losses[] = new DesignLoss($path, DesignLossCode::StyleDropped, sprintf(
                '%s has an outline stroke; the DSL has no word for it and saving removes it.',
                $path,
            ));
        }

        $backgroundColor = self::string($object, 'backgroundColor');

        if ($backgroundColor !== null && trim($backgroundColor) !== '') {
            $losses[] = new DesignLoss($path, DesignLossCode::StyleDropped, sprintf(
                '%s has a background fill behind it; the DSL has no word for it and saving removes it.',
                $path,
            ));
        }

        if (($object['clipPath'] ?? null) !== null) {
            $losses[] = new DesignLoss($path, DesignLossCode::StyleDropped, sprintf(
                '%s is clipped by a clip path; the DSL has no word for it and saving removes the clipping.',
                $path,
            ));
        }

        if (!$allowEditorLock && ($object['editorLocked'] ?? false) === true) {
            $losses[] = new DesignLoss($path, DesignLossCode::StyleDropped, sprintf(
                '%s is locked in the editor; the DSL authors that flag only on the background layer, so saving unlocks it.',
                $path,
            ));
        }
    }

    /**
     * @param array<array-key, mixed> $object
     * @param list<DesignLoss> $losses
     */
    private static function reportTextStyles(string $path, array $object, array &$losses): void
    {
        /** @var mixed $styles */
        $styles = $object['styles'] ?? null;

        if (is_array($styles) && $styles !== []) {
            $losses[] = new DesignLoss($path, DesignLossCode::StyleDropped, sprintf(
                '%s carries per-character styling (a differently coloured or sized run inside the text). The DSL styles a text as a whole, so saving flattens it to one font, one size and one colour.',
                $path,
            ));
        }

        foreach (['underline' => 'underlined', 'linethrough' => 'struck through', 'overline' => 'overlined'] as $key => $label) {
            if (($object[$key] ?? false) === true) {
                $losses[] = new DesignLoss($path, DesignLossCode::StyleDropped, sprintf(
                    '%s is %s; the DSL has no text decoration and saving removes it.',
                    $path,
                    $label,
                ));
            }
        }

        $charSpacing = self::number($object, 'charSpacing') ?? 0.0;

        if (abs($charSpacing) > self::GEOMETRY_EPSILON) {
            $losses[] = new DesignLoss($path, DesignLossCode::StyleDropped, sprintf(
                '%s has letter spacing (%s); the DSL has no word for it and saving resets it to 0 — which re-wraps the text.',
                $path,
                self::formatNumber($charSpacing),
            ));
        }

        $textBackground = self::string($object, 'textBackgroundColor');

        if ($textBackground !== null && trim($textBackground) !== '') {
            $losses[] = new DesignLoss($path, DesignLossCode::StyleDropped, sprintf(
                '%s has a highlight colour behind its glyphs; the DSL has no word for it and saving removes it.',
                $path,
            ));
        }

        if (($object['splitByGrapheme'] ?? false) === true) {
            $losses[] = new DesignLoss($path, DesignLossCode::StyleDropped, sprintf(
                '%s wraps mid-word (splitByGrapheme); the DSL has no word for it and saving restores word wrapping, which re-wraps the text.',
                $path,
            ));
        }

        if (($object['path'] ?? null) !== null) {
            $losses[] = new DesignLoss($path, DesignLossCode::TransformDropped, sprintf(
                '%s is text on a path; the DSL lays text out in a rectangle and saving straightens it.',
                $path,
            ));
        }
    }

    /**
     * @param array<array-key, mixed> $object
     * @param list<DesignLoss> $losses
     */
    private static function reportImageStyles(string $path, array $object, array &$losses): void
    {
        /** @var mixed $filters */
        $filters = $object['filters'] ?? null;

        if (is_array($filters) && $filters !== []) {
            $losses[] = new DesignLoss($path, DesignLossCode::StyleDropped, sprintf(
                '%s has %d image filter(s) applied; the DSL has no word for them and saving removes them.',
                $path,
                count($filters),
            ));
        }

        foreach (['cropX', 'cropY'] as $key) {
            $crop = self::number($object, $key) ?? 0.0;

            if (abs($crop) > self::GEOMETRY_EPSILON) {
                $losses[] = new DesignLoss($path, DesignLossCode::TransformDropped, sprintf(
                    '%s is cropped (%s = %s); the DSL fits whole pictures into a rect and saving un-crops it.',
                    $path,
                    $key,
                    self::formatNumber($crop),
                ));
            }
        }
    }

    /**
     * Per-input machinery beyond the seven keys of DSL v1 — the lists /
     * checkbox-lists / checklist stack, and the free-text `description`.
     *
     * Reported from the persisted input where there is one and from the canvas
     * object otherwise, because both carry the same flags and a textbox the
     * `inputs[]` array does not reach still has them on the object.
     *
     * @param array<array-key, mixed> $object
     * @param list<DesignLoss> $losses
     */
    private static function reportInputFeatures(string $path, array $object, null|EditorTextInput $input, array &$losses): void
    {
        $description = $input !== null ? $input->description : self::string($object, 'description');

        if ($description !== null && trim($description) !== '') {
            $losses[] = new DesignLoss($path . '.input', DesignLossCode::InputFeatureDropped, sprintf(
                '%s.input has a description ("%s") for the person filling it in; DSL v1 has no key for it and saving clears it.',
                $path,
                $description,
            ));
        }

        $checklist = $input !== null ? $input->checklist : ($object['checklist'] ?? false) === true;

        if ($checklist) {
            $losses[] = new DesignLoss($path . '.input', DesignLossCode::InputFeatureDropped, sprintf(
                '%s.input is a CHECKLIST component (its own item editor on the fill page). DSL v1 has no key for it, so saving turns it back into an ordinary text input and the checkboxes disappear from the render.',
                $path,
            ));

            return; // lists + listCheckboxes are implied by it; one sentence is enough
        }

        $lists = $input !== null ? $input->lists : ($object['lists'] ?? false) === true;
        $checkboxes = $input !== null ? $input->listCheckboxes : ($object['listCheckboxes'] ?? false) === true;

        if ($lists || $checkboxes) {
            $losses[] = new DesignLoss($path . '.input', DesignLossCode::InputFeatureDropped, sprintf(
                '%s.input allows %s inside its rich text. DSL v1 has no key for them, so saving disables the feature and any list styling the designer set is lost.',
                $path,
                $checkboxes ? 'bulleted, numbered and checkbox lists' : 'bulleted and numbered lists',
            ));
        }
    }

    /**
     * @param list<DesignLoss> $losses
     */
    private static function reportImageInputFeatures(string $path, EditorImageInput $input, array &$losses): void
    {
        if ($input->description !== null && trim($input->description) !== '') {
            $losses[] = new DesignLoss($path . '.input', DesignLossCode::InputFeatureDropped, sprintf(
                '%s.input has a description ("%s") for the person filling it in; DSL v1 has no key for it and saving clears it.',
                $path,
                $input->description,
            ));
        }
    }

    // -----------------------------------------------------------------
    // scalar readers
    // -----------------------------------------------------------------

    /**
     * @param array<array-key, mixed> $object
     */
    private static function string(array $object, string $key): null|string
    {
        /** @var mixed $value */
        $value = $object[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Legacy v5 canvases carry some numerics as strings, which is why this is
     * not a plain `is_float` check.
     *
     * @param array<array-key, mixed> $object
     */
    private static function number(array $object, string $key): null|float
    {
        /** @var mixed $value */
        $value = $object[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            $value = (float) $value;

            return is_finite($value) ? $value : null;
        }

        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;

            return is_finite($value) ? $value : null;
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $object
     */
    private static function integer(array $object, string $key): null|int
    {
        $value = self::number($object, $key);

        return $value === null ? null : (int) round($value);
    }

    private static function positive(float $value, float $fallback): float
    {
        return $value > 0.0 && is_finite($value) ? $value : $fallback;
    }

    private static function blankToNull(null|string $value): null|string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }

    /**
     * Is there a real picture here to lose?
     *
     * An empty image slot is a legitimate design — a placeholder with no
     * stand-in — and the compiler gives it the 1x1 transparent data URI so
     * `loadFromJSON` settles. Reporting THAT as an unnameable picture would
     * mean a design the compiler itself produced decompiles with a loss, and
     * the round trip would never reach a fixed point.
     *
     * @param array<array-key, mixed> $object
     */
    private static function showsAPicture(array $object): bool
    {
        if (self::string($object, 'assetPath') !== null) {
            return true;
        }

        $src = self::string($object, 'src');

        return $src !== null && str_starts_with($src, 'http');
    }

    /**
     * The gallery id stamped on the object, when it is one. `assetId` is a
     * `CANVAS_CUSTOM_PROPERTY` the editor writes for pictures picked from the
     * gallery; anything else there is not an id the DSL can use.
     *
     * @param array<array-key, mixed> $object
     */
    private static function galleryId(array $object): null|string
    {
        $assetId = self::string($object, 'assetId');

        return $assetId !== null && Uuid::isValid($assetId) ? $assetId : null;
    }

    /**
     * Fabric writes a fill as `#rrggbb`, `rgb(r,g,b)` or `rgba(r,g,b,a)`; the
     * DSL speaks lowercase `#rrggbb` only, normalized through the app-wide
     * {@see RichText::normalizeHexColor()}.
     *
     * @param array<array-key, mixed> $object
     * @param list<DesignLoss> $losses
     */
    private static function textColor(array $object, string $path, array &$losses): string
    {
        /** @var mixed $fill */
        $fill = $object['fill'] ?? null;

        if ($fill === null || $fill === '') {
            return TextElement::DEFAULT_COLOR;
        }

        if (!is_string($fill)) {
            $losses[] = new DesignLoss($path . '.color', DesignLossCode::StyleDropped, sprintf(
                '%s.color is a gradient or pattern; the DSL carries a flat colour only, so it becomes %s.',
                $path,
                TextElement::DEFAULT_COLOR,
            ));

            return TextElement::DEFAULT_COLOR;
        }

        $normalized = self::normalizeColor($fill);

        if ($normalized === null) {
            $losses[] = new DesignLoss($path . '.color', DesignLossCode::StyleDropped, sprintf(
                '%s.color ("%s") is not a plain opaque colour; it becomes %s.',
                $path,
                $fill,
                TextElement::DEFAULT_COLOR,
            ));

            return TextElement::DEFAULT_COLOR;
        }

        return $normalized;
    }

    /**
     * `#rgb` / `#rrggbb` / `rgb()` / fully-opaque `rgba()` → `#rrggbb`.
     * Anything with real transparency returns null: the DSL has no alpha, and
     * silently dropping it would change the picture.
     */
    private static function normalizeColor(string $value): null|string
    {
        $hex = RichText::normalizeHexColor($value);

        if ($hex !== null) {
            return $hex;
        }

        if (preg_match('/^rgba?\(\s*([0-9.]+)\s*,\s*([0-9.]+)\s*,\s*([0-9.]+)\s*(?:,\s*([0-9.]+)\s*)?\)$/i', trim($value), $matches) !== 1) {
            return null;
        }

        if (isset($matches[4]) && abs((float) $matches[4] - 1.0) > 1.0e-4) {
            return null;
        }

        return sprintf(
            '#%02x%02x%02x',
            max(0, min(255, (int) round((float) $matches[1]))),
            max(0, min(255, (int) round((float) $matches[2]))),
            max(0, min(255, (int) round((float) $matches[3]))),
        );
    }

    /**
     * A number in an error message: no trailing zeros, no exponent.
     */
    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
