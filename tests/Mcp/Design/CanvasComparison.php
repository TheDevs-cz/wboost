<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Design;

use WBoost\Web\Mcp\Design\CompilationContext;
use WBoost\Web\Mcp\Design\DecompilationContext;
use WBoost\Web\Mcp\Design\DesignAsset;
use WBoost\Web\Mcp\Design\Dsl\ContainerElement;
use WBoost\Web\Mcp\Design\Dsl\DesignDocument;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\RichText;

/**
 * The oracle behind `DesignRoundTripTest`: it decides what "the same canvas"
 * means, and it is therefore the part of that test that can quietly make it
 * meaningless. Every exclusion below is named and justified — an unexplained
 * one is a bug hiding.
 *
 * ## What {@see project()} compares
 *
 * Per canvas OBJECT (only the ones the DSL can hold — visible `Textbox` /
 * `Image` — with the background hoisted to index 0, because that is where the
 * compiler pins it):
 *
 * | compared | why |
 * |---|---|
 * | `type`, stack POSITION | z-order is the design |
 * | `left`, `top`, displayed width/height | the pixels |
 * | `angle` | rotation is not expressible; a rotated fixture MUST fail here |
 * | `text`, `fontFamily`, effective `fontSize`, `fill`, `textAlign`, `lineHeight` | what the text looks like |
 * | `inputId`, `name`, `maxLength`, `locked`, `uppercase`, `hidable`, `richText`, `sampleValue`, `allowedFonts`, `fontChoice`, `allowedColors` | the fill contract |
 * | `imagePlaceholder`, `isBackground`, `allowMove/Resize/Rotate`, `allowedDirectoryIds` | the slot contract |
 * | resolved `assetId` | WHICH gallery picture the object shows |
 *
 * Plus the `containers` definitions in full, the ordered text `inputs[]`, and
 * the `imageInputs[]` keyed by id (their array order is presentational — see
 * {@see \WBoost\Web\Mcp\Design\CompiledDesign}).
 *
 * ## What it deliberately EXCLUDES, and why
 *
 * - **`version`** — a constant stamp on the canvas and on every object.
 * - **`src` and `assetPath`** — both are properties of the gallery ROW the
 *   `assetId` names (the storage key and its public URL), not of the design.
 *   The compiler writing them is plan §4.2-9 and `DesignCompilerTest` asserts
 *   it; repeating that here would only assert the URL builder twice.
 * - **raw `width` / `height` / `scaleX` / `scaleY`** — on a Fabric image these
 *   are the picture's NATURAL size times a scale. The natural size is a
 *   property of the asset, not of the design; the same design over a
 *   re-uploaded 2x picture is the same design. Only the product renders, so
 *   only the product is compared. (For a textbox they are compared, as the
 *   wrap width and the effective font size.)
 * - **a textbox's `height`** — Fabric computes it from the wrapped content and
 *   plan §4.2-6 forbids authoring one, so the compiler emits none by design.
 * - **`description` and the whole list / checkbox-list / checklist stack** —
 *   DSL v1 has no keys for them (plan §3.4). They are REPORTED as
 *   {@see \WBoost\Web\Mcp\Design\DesignLoss}es instead, and the round-trip
 *   test pins the exact loss list per fixture, so nothing is lost silently.
 * - **`editorLocked` off the background layer** — same: reported, not carried.
 * - **every Fabric painting / interaction default** (`strokeWidth`, `styles`,
 *   `minWidth`, `paintFirst`, `crossOrigin`, `editable`, …). The compiler
 *   emits a minimal object and Fabric fills the rest with the identical
 *   defaults on load; asserting them would assert Fabric, not the compiler.
 */
final class CanvasComparison
{
    /** Plan S4-T5: floats agree within 0.01 canvas pixels. */
    public const float TOLERANCE = 0.01;

    private function __construct()
    {
    }

    /**
     * The comparable projection of a canvas + its input arrays.
     *
     * @param array<array-key, mixed> $canvas
     * @param list<EditorTextInput> $textInputs
     * @param list<EditorImageInput> $imageInputs
     * @param list<string> $keepContainerIds the container definitions to
     *        compare — the ids the decompiler kept. Definitions it dropped
     *        (a member that is a fillable placeholder, a container left with
     *        one item) are excluded here and carried by the loss list; the
     *        CONTENT of the kept ones still comes from the canvas, so this
     *        filter cannot hide a wrong member list or a lost maxHeight.
     * @param array<string, DesignAsset> $assets as {@see assets()} returns —
     *        what decides whether a picture is nameable in the DSL at all
     * @return array<string, mixed>
     */
    public static function project(array $canvas, array $textInputs, array $imageInputs, array $keepContainerIds, array $assets): array
    {
        $objects = self::representableObjects($canvas, $assets, $textInputs, $imageInputs);

        /** @var list<array<string, mixed>> $projected */
        $projected = [];

        foreach ($objects as $entry) {
            $projected[] = self::projectObject($entry['object'], $entry['input'], $entry['imageInput'], $assets);
        }

        return [
            'background' => self::normalizeColor($canvas['background'] ?? null),
            'objects' => $projected,
            'containers' => self::projectContainers($canvas, $keepContainerIds),
            'inputs' => self::projectTextInputs($textInputs, self::visibleTextboxCount($canvas)),
            'imageInputs' => self::projectImageInputs($imageInputs, array_column($objects, 'object')),
        ];
    }

    /**
     * Human-readable differences between two projections, floats compared
     * within {@see TOLERANCE}. Empty = identical.
     *
     * @return list<string>
     */
    public static function diff(mixed $expected, mixed $actual, string $path = ''): array
    {
        if (is_float($expected) || is_int($expected)) {
            if (!is_float($actual) && !is_int($actual)) {
                return [sprintf('%s: expected number %s, got %s', $path, self::describe($expected), self::describe($actual))];
            }

            return abs((float) $expected - (float) $actual) <= self::TOLERANCE
                ? []
                : [sprintf('%s: expected %s, got %s', $path, self::describe($expected), self::describe($actual))];
        }

        if (is_array($expected)) {
            if (!is_array($actual)) {
                return [sprintf('%s: expected an array, got %s', $path, self::describe($actual))];
            }

            /** @var list<string> $differences */
            $differences = [];

            foreach ($expected as $key => $value) {
                if (!array_key_exists($key, $actual)) {
                    $differences[] = sprintf('%s.%s: missing (expected %s)', $path, (string) $key, self::describe($value));

                    continue;
                }

                /** @var mixed $actualValue */
                $actualValue = $actual[$key];
                $differences = array_merge($differences, self::diff($value, $actualValue, $path . '.' . (string) $key));
            }

            foreach ($actual as $key => $value) {
                if (!array_key_exists($key, $expected)) {
                    $differences[] = sprintf('%s.%s: unexpected (%s)', $path, (string) $key, self::describe($value));
                }
            }

            return $differences;
        }

        return $expected === $actual
            ? []
            : [sprintf('%s: expected %s, got %s', $path, self::describe($expected), self::describe($actual))];
    }

    // -----------------------------------------------------------------
    // contexts — what a real factory would have said about this canvas
    // -----------------------------------------------------------------

    /**
     * The gallery pictures a canvas references, as
     * {@see \WBoost\Web\Mcp\Design\DecompilationContextFactory} resolves them
     * against a real database: storage path → row, with the row's NATURAL size.
     * Reproduced here so the round-trip tests stay pure unit tests — this file
     * has no kernel and no gallery to look anything up in.
     *
     * Two things are taken from the canvas rather than invented. The gallery
     * id comes from the object's `assetId` when the editor stamped one and
     * otherwise from the path's basename — which for `file-upload/{project}/{fileId}.ext`
     * IS the `FileUpload` UUID. The natural size comes from the object's raw
     * `width`/`height`, because that is exactly what those mean on a Fabric
     * image. A path that yields no UUID resolves to nothing, which is the
     * production case that matters: a background uploaded through the variant
     * form has no gallery row at all.
     *
     * @param array<array-key, mixed> $canvas
     * @return array<string, DesignAsset>
     */
    public static function assets(array $canvas): array
    {
        /** @var array<string, DesignAsset> $assets */
        $assets = [];
        /** @var mixed $objects */
        $objects = $canvas['objects'] ?? [];

        if (!is_array($objects)) {
            return [];
        }

        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }

            $path = self::storagePath($object);

            if ($path === null || isset($assets[$path])) {
                continue;
            }

            /** @var mixed $stamped */
            $stamped = $object['assetId'] ?? null;
            $id = is_string($stamped) && \Ramsey\Uuid\Uuid::isValid($stamped)
                ? $stamped
                : pathinfo($path, PATHINFO_FILENAME);

            if (!\Ramsey\Uuid\Uuid::isValid($id)) {
                continue;
            }

            $width = self::number($object, 'width');
            $height = self::number($object, 'height');

            $assets[$path] = new DesignAsset(
                id: $id,
                path: $path,
                url: 'https://example.invalid/' . $path,
                width: $width === null || $width <= 0.0 ? null : (int) round($width),
                height: $height === null || $height <= 0.0 ? null : (int) round($height),
            );
        }

        return $assets;
    }

    /**
     * @param array<string, DesignAsset> $assets keyed by path
     */
    public static function decompilationContext(array $assets): DecompilationContext
    {
        /** @var array<string, DesignAsset> $byUrl */
        $byUrl = [];

        foreach ($assets as $asset) {
            $byUrl[$asset->url] = $asset;
        }

        return new DecompilationContext($assets, $byUrl);
    }

    /**
     * The storage key an image object points at: its `assetPath`, or — for the
     * pre-stamping placeholders that carry only a `src` — the key that URL is
     * the public form of. `UploaderHelper::getPublicPath()` prefixes the key
     * with a host and a bucket, so stripping both is its inverse.
     *
     * @param array<array-key, mixed> $object
     */
    private static function storagePath(array $object): null|string
    {
        $path = self::string($object, 'assetPath');

        if ($path !== null && $path !== '') {
            return $path;
        }

        $src = self::string($object, 'src');

        if ($src === null || !str_starts_with($src, 'http')) {
            return null;
        }

        $stripped = preg_replace('#^https?://[^/]+/#', '', $src);

        return $stripped === null || $stripped === '' || $stripped === $src ? null : $stripped;
    }

    /**
     * The project context for the compile half.
     *
     * The font whitelist is every family the DECOMPILED document names. That
     * is deliberate: whether a canvas's font is one of its project's uploaded
     * faces is a `get_context` question that has nothing to do with whether
     * the geometry round-trips, and letting a font error abort the compile
     * would mask every other assertion behind it.
     *
     * @param array<string, DesignAsset> $assets keyed by path (as {@see assets()} returns)
     */
    public static function compilationContext(DesignDocument $document, array $assets): CompilationContext
    {
        /** @var list<string> $fonts */
        $fonts = [];

        foreach ($document->textElements() as $element) {
            if (!in_array($element->font, $fonts, true)) {
                $fonts[] = $element->font;
            }
        }

        /** @var array<string, DesignAsset> $byId */
        $byId = [];

        foreach ($assets as $asset) {
            $byId[$asset->id] = $asset;
        }

        return new CompilationContext($fonts, $byId);
    }

    /**
     * @return list<string>
     */
    public static function containerIds(DesignDocument $document): array
    {
        return array_map(
            static fn (ContainerElement $container): string => $container->id,
            $document->containerElements(),
        );
    }

    // -----------------------------------------------------------------
    // projection internals
    // -----------------------------------------------------------------

    /**
     * The objects DSL v1 can hold, in the order the compiler will emit them:
     * the background first (plan §4.3-11 pins it to stack index 0), then the
     * rest in stack order. Invisible objects and types with no `kind` are
     * excluded — dropping them is exactly what the decompiler reports.
     *
     * A BACKGROUND whose picture is not a gallery row is excluded too, and
     * that one deserves its own sentence: the DSL names pictures by gallery id
     * and a background element without one compiles to no object at all
     * ({@see \WBoost\Web\Mcp\Design\DesignCompiler::compileBackground()}), so
     * the honest expectation is that it is gone. It is not swept under the
     * rug — the decompiler reports `asset_unresolved` and the round-trip test
     * pins that code per fixture.
     *
     * Each entry also carries the `EditorTextInput` the POSITIONAL contract
     * binds to it (plan §4.1-1: the i-th visible `Textbox` is `inputs[i]`),
     * because `inputs[]` — not the canvas object's mirror of the same
     * properties — is what the renderer resolves overrides against. Production
     * canvases carry mirrors that are missing (the fixtures' hand-written
     * canvases) or stale; the round trip heals them, and the healed value is
     * the one that was already in force.
     *
     * @param array<array-key, mixed> $canvas
     * @param array<string, DesignAsset> $assets
     * @param list<EditorTextInput> $textInputs
     * @param list<EditorImageInput> $imageInputs
     * @return list<array{object: array<array-key, mixed>, input: null|EditorTextInput, imageInput: null|EditorImageInput}>
     */
    private static function representableObjects(array $canvas, array $assets, array $textInputs, array $imageInputs): array
    {
        /** @var array<string, EditorImageInput> $imageInputsById */
        $imageInputsById = [];

        foreach ($imageInputs as $imageInput) {
            $imageInputsById[$imageInput->inputId] ??= $imageInput;
        }

        /** @var mixed $objects */
        $objects = $canvas['objects'] ?? [];

        if (!is_array($objects)) {
            return [];
        }

        /** @var list<array{object: array<array-key, mixed>, input: null|EditorTextInput, imageInput: null|EditorImageInput}> $background */
        $background = [];
        /** @var list<array{object: array<array-key, mixed>, input: null|EditorTextInput, imageInput: null|EditorImageInput}> $rest */
        $rest = [];
        $textboxIndex = 0;

        foreach ($objects as $object) {
            if (!is_array($object) || ($object['visible'] ?? true) === false) {
                continue;
            }

            /** @var mixed $type */
            $type = $object['type'] ?? null;
            $type = is_string($type) ? strtolower($type) : '';

            if ($type !== 'textbox' && $type !== 'image') {
                continue;
            }

            if ($type === 'image' && ($object['isBackground'] ?? false) === true) {
                if ($background === [] && self::resolvedAssetId($object, $assets) !== null) {
                    $background[] = [
                        'object' => $object,
                        'input' => null,
                        'imageInput' => $imageInputsById[(string) self::string($object, 'inputId')] ?? null,
                    ];
                }

                continue;
            }

            $input = null;

            if ($type === 'textbox') {
                $input = $textInputs[$textboxIndex] ?? null;
                $textboxIndex++;
            }

            $rest[] = [
                'object' => $object,
                'input' => $input,
                'imageInput' => $type === 'image' ? ($imageInputsById[(string) self::string($object, 'inputId')] ?? null) : null,
            ];
        }

        return array_merge($background, $rest);
    }

    /**
     * @param array<array-key, mixed> $object
     * @param null|EditorTextInput $input the positionally bound input, when the
     *        object is a textbox and `inputs[]` reaches it — authoritative over
     *        the object's own mirror of the same properties
     * @param null|EditorImageInput $imageInput the same, for an image object,
     *        bound by its own (reliable) `inputId`
     * @param array<string, DesignAsset> $assets
     * @return array<string, mixed>
     */
    private static function projectObject(array $object, null|EditorTextInput $input, null|EditorImageInput $imageInput, array $assets): array
    {
        /** @var mixed $rawType */
        $rawType = $object['type'] ?? null;
        $type = is_string($rawType) ? strtolower($rawType) : '';
        $scaleX = self::number($object, 'scaleX') ?? 1.0;
        $scaleY = self::number($object, 'scaleY') ?? 1.0;
        $isText = $type === 'textbox';

        $projection = [
            'type' => $type,
            'left' => self::number($object, 'left') ?? 0.0,
            'top' => self::number($object, 'top') ?? 0.0,
            'width' => (self::number($object, 'width') ?? 0.0) * $scaleX,
            'angle' => self::number($object, 'angle') ?? 0.0,
            'inputId' => $isText && $input !== null
                ? $input->inputId
                : self::string($object, 'inputId'),
            'name' => match (true) {
                $isText && $input !== null => self::blankToNull($input->name),
                !$isText && $imageInput !== null => self::blankToNull($imageInput->name),
                default => self::blankToNull(self::string($object, 'name')),
            },
            'imagePlaceholder' => ($object['imagePlaceholder'] ?? false) === true,
            'isBackground' => ($object['isBackground'] ?? false) === true,
        ];

        if ($isText) {
            $maxLength = $input !== null ? $input->maxLength : self::integer($object, 'maxLength');

            return $projection + [
                'text' => self::string($object, 'text') ?? '',
                'fontFamily' => self::blankToNull(self::string($object, 'fontFamily')) ?? 'Times New Roman',
                'fontSize' => (self::number($object, 'fontSize') ?? 40.0) * $scaleY,
                'fill' => self::normalizeColor($object['fill'] ?? null) ?? '#000000',
                'textAlign' => strtolower(self::string($object, 'textAlign') ?? 'left'),
                'lineHeight' => self::number($object, 'lineHeight') ?? 1.16,
                'maxLength' => $maxLength === null || $maxLength < 1 ? null : $maxLength,
                'locked' => $input !== null ? $input->locked : ($object['locked'] ?? false) === true,
                'uppercase' => $input !== null ? $input->uppercase : ($object['uppercase'] ?? false) === true,
                'hidable' => $input !== null ? $input->hidable : ($object['hidable'] ?? false) === true,
                'richText' => $input !== null ? $input->richText : ($object['richText'] ?? false) === true,
                'sampleValue' => self::blankToNull($input !== null ? $input->sampleValue : self::string($object, 'sampleValue')),
                'allowedFonts' => $input !== null ? $input->allowedFonts : (is_array($object['allowedFonts'] ?? null) ? array_values($object['allowedFonts']) : []),
                'fontChoice' => $input !== null ? $input->fontChoice : ($object['fontChoice'] ?? false) === true,
                'allowedColors' => $input !== null ? $input->allowedColors : (is_array($object['allowedColors'] ?? null) ? array_values($object['allowedColors']) : null),
            ];
        }

        // Which gallery PICTURE the object shows, not which storage key: the
        // key is a property of the row the id names, and a picture the DSL
        // cannot name comes back as no picture at all — which is exactly what
        // `asset_unresolved` says will happen.
        $projection['height'] = (self::number($object, 'height') ?? 0.0) * $scaleY;
        $projection['assetId'] = self::resolvedAssetId($object, $assets);

        return $projection;
    }

    /**
     * The gallery id a canvas object resolves to, by the same rule
     * {@see \WBoost\Web\Mcp\Design\DesignDecompiler} uses: the resolved row
     * first, the object's own stamp second, nothing third.
     *
     * @param array<array-key, mixed> $object
     * @param array<string, DesignAsset> $assets
     */
    private static function resolvedAssetId(array $object, array $assets): null|string
    {
        $path = self::storagePath($object);

        if ($path !== null && isset($assets[$path])) {
            return $assets[$path]->id;
        }

        $stamped = self::string($object, 'assetId');

        return $stamped !== null && \Ramsey\Uuid\Uuid::isValid($stamped) ? $stamped : null;
    }

    /**
     * @param array<array-key, mixed> $canvas
     * @param list<string> $keepContainerIds
     * @return list<array<string, mixed>>
     */
    private static function projectContainers(array $canvas, array $keepContainerIds): array
    {
        /** @var list<array<string, mixed>> $projected */
        $projected = [];

        foreach (\WBoost\Web\Value\CanvasContainer::collectionFromCanvas($canvas) as $container) {
            if (!in_array($container->id, $keepContainerIds, true)) {
                continue;
            }

            $projected[] = [
                'id' => $container->id,
                'maxHeight' => $container->maxHeight,
                'memberInputIds' => $container->memberInputIds,
                'memberContainerIds' => $container->memberContainerIds,
                'gap' => $container->gap,
                'spaceAfter' => $container->spaceAfter,
            ];
        }

        return $projected;
    }

    /**
     * Inputs beyond the visible-textbox count have no object to bind to and
     * cannot survive a round trip through a document that has no element for
     * them — the decompiler reports each one.
     *
     * @param list<EditorTextInput> $inputs
     * @return list<array<string, mixed>>
     */
    private static function projectTextInputs(array $inputs, int $visibleTextboxes): array
    {
        /** @var list<array<string, mixed>> $projected */
        $projected = [];

        foreach (array_slice($inputs, 0, $visibleTextboxes) as $input) {
            $projected[] = [
                'inputId' => $input->inputId,
                'name' => self::blankToNull($input->name),
                'maxLength' => $input->maxLength !== null && $input->maxLength >= 1 ? $input->maxLength : null,
                'locked' => $input->locked,
                'uppercase' => $input->uppercase,
                'hidable' => $input->hidable,
                'richText' => $input->richText,
                'sampleValue' => self::blankToNull($input->sampleValue),
                'allowedFonts' => $input->allowedFonts,
                'fontChoice' => $input->fontChoice,
                'allowedColors' => $input->allowedColors,
            ];
        }

        return $projected;
    }

    /**
     * The fill contract of every visible image PLACEHOLDER, keyed by `inputId`
     * (their array order is presentational — see
     * {@see \WBoost\Web\Mcp\Design\CompiledDesign}).
     *
     * Driven by the OBJECTS, with the `imageInputs[]` row layered on where
     * there is one. Two production realities make that the right way round:
     * a row whose object is gone binds to nothing, and — seen in the fixtures
     * — a canvas object's mirror of `allowMove` / `allowResize` can be STALE
     * while the row is current. The row is what
     * {@see \WBoost\Web\Services\SocialNetwork\ResolveImageOverrides} enforces,
     * so the row wins and the round trip heals the mirror; the mirror itself
     * is asserted by `DesignCompilerTest`.
     *
     * @param list<EditorImageInput> $inputs
     * @param list<array<array-key, mixed>> $objects
     * @return array<string, array<string, mixed>>
     */
    private static function projectImageInputs(array $inputs, array $objects): array
    {
        /** @var array<string, EditorImageInput> $byId */
        $byId = [];

        foreach ($inputs as $input) {
            $byId[$input->inputId] ??= $input;
        }

        /** @var array<string, array<string, mixed>> $projected */
        $projected = [];

        foreach ($objects as $object) {
            $inputId = self::string($object, 'inputId');

            if ($inputId === null || ($object['imagePlaceholder'] ?? false) !== true) {
                continue;
            }

            $input = $byId[$inputId] ?? null;
            $isBackground = ($object['isBackground'] ?? false) === true;

            $projected[$inputId] = $input !== null
                ? [
                    'name' => self::blankToNull($input->name),
                    'allowMove' => !$isBackground && $input->allowMove,
                    'allowResize' => !$isBackground && $input->allowResize,
                    'allowRotate' => !$isBackground && $input->allowRotate,
                    'hidable' => !$isBackground && $input->hidable,
                    'allowedDirectoryIds' => $isBackground ? [] : $input->allowedDirectoryIds,
                    'isBackground' => $isBackground,
                ]
                : [
                    'name' => self::blankToNull(self::string($object, 'name')),
                    'allowMove' => !$isBackground && ($object['allowMove'] ?? true) === true,
                    'allowResize' => !$isBackground && ($object['allowResize'] ?? true) === true,
                    'allowRotate' => !$isBackground && ($object['allowRotate'] ?? false) === true,
                    'hidable' => !$isBackground && ($object['hidable'] ?? false) === true,
                    'allowedDirectoryIds' => $isBackground ? [] : self::stringList($object['allowedDirectoryIds'] ?? null),
                    'isBackground' => $isBackground,
                ];
        }

        ksort($projected);

        return $projected;
    }

    /**
     * @param array<array-key, mixed> $canvas
     */
    private static function visibleTextboxCount(array $canvas): int
    {
        /** @var mixed $objects */
        $objects = $canvas['objects'] ?? [];
        $count = 0;

        if (!is_array($objects)) {
            return 0;
        }

        foreach ($objects as $object) {
            if (!is_array($object) || ($object['visible'] ?? true) === false) {
                continue;
            }

            /** @var mixed $type */
            $type = $object['type'] ?? null;

            if (is_string($type) && strtolower($type) === 'textbox') {
                $count++;
            }
        }

        return $count;
    }

    // -----------------------------------------------------------------
    // readers
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
     * @param array<array-key, mixed> $object
     */
    private static function integer(array $object, string $key): null|int
    {
        $value = self::number($object, $key);

        return $value === null ? null : (int) round($value);
    }

    /**
     * @param array<array-key, mixed> $object
     */
    private static function number(array $object, string $key): null|float
    {
        /** @var mixed $value */
        $value = $object[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) && is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        /** @var list<string> $list */
        $list = [];

        if (!is_array($value)) {
            return $list;
        }

        foreach ($value as $entry) {
            if (is_string($entry)) {
                $list[] = $entry;
            }
        }

        return $list;
    }

    private static function blankToNull(null|string $value): null|string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }

    /**
     * Fabric writes `rgb()`/`rgba()` where the DSL writes `#rrggbb`; both
     * sides are normalized so the notation cannot masquerade as a difference.
     */
    private static function normalizeColor(mixed $value): null|string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $hex = RichText::normalizeHexColor($value);

        if ($hex !== null) {
            return $hex;
        }

        if (preg_match('/^rgba?\(\s*([0-9.]+)\s*,\s*([0-9.]+)\s*,\s*([0-9.]+)/i', trim($value), $matches) !== 1) {
            return null;
        }

        return sprintf(
            '#%02x%02x%02x',
            (int) round((float) $matches[1]),
            (int) round((float) $matches[2]),
            (int) round((float) $matches[3]),
        );
    }

    private static function describe(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_float($value) || is_int($value)) {
            return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }

        if (is_string($value)) {
            return '"' . (mb_strlen($value) > 60 ? mb_substr($value, 0, 57) . '…' : $value) . '"';
        }

        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }

        return get_debug_type($value);
    }
}
