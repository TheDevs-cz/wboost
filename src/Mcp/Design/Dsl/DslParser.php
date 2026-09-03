<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

use Ramsey\Uuid\Uuid;
use WBoost\Web\Exceptions\InvalidDesignDocument;
use WBoost\Web\Value\CanvasShapeGradient;
use WBoost\Web\Value\CanvasShapeKind;
use WBoost\Web\Value\CanvasShapeStroke;
use WBoost\Web\Value\RichText;

/**
 * The strict parser for the design DSL (plan §3.4) — decoded JSON in, a
 * validated {@see DesignDocument} out, {@see InvalidDesignDocument} otherwise.
 *
 * ## Why it lives in `Dsl/` and is used statically
 *
 * `src/Mcp/Design/Dsl/` is excluded from the service container (S0-T4) because
 * it holds value objects, and this parser is a **pure function of its input**:
 * it has no dependencies, no state that outlives a call, and — by design — no
 * project context at all. That last part is the load-bearing one. Face-string
 * membership, gallery-image existence and brand-palette conformance are
 * checked by the compiler and the linter, which DO know the project; keeping
 * the parser context-free is what stops "is this font real?" from leaking into
 * "is this document well-formed?". A service would give it a lifecycle it does
 * not need and an injection point that invites exactly that leak. Same
 * reasoning, same shape as `RichText::fromRaw()` / `CanvasContainer::fromArray()`
 * in `src/Value/`.
 *
 * ## Why it is strict
 *
 * Plan §3.4: *"Unknown keys rejected (agents hallucinate keys — silent
 * acceptance produces silently wrong designs)."* An agent that writes
 * `fontSize` instead of `size`, or `colour` instead of `color`, is told, with
 * the nearest valid key suggested. Silently applying the default instead
 * produces a design that is wrong in a way nobody can see in the JSON.
 *
 * ## Why it reports every problem at once
 *
 * Validation continues past the first failure and the exception carries the
 * whole list ({@see InvalidDesignDocument::$violations}). An authoring agent
 * that gets five problems in one response fixes them in one turn; dying on the
 * first costs five round trips of a tool that also renders. Within a single
 * node validation does short-circuit — once `at` is known not to be an object
 * there is nothing sensible to say about `at.col`.
 *
 * ## Grammar surface
 *
 * The `*_KEYS` constants below ARE the grammar's key sets: they drive
 * validation, the "allowed keys" half of every unknown-key message, and
 * (S6-T3) the Skill's generated DSL reference, so the documentation cannot
 * drift from the parser.
 */
final class DslParser
{
    /** @var list<string> */
    public const array ROOT_KEYS = ['canvas', 'elements'];

    /** @var list<string> */
    public const array CANVAS_KEYS = ['width', 'height', 'background'];

    /** @var list<string> */
    public const array CANVAS_BACKGROUND_KEYS = ['image', 'fill'];

    /** @var list<string> */
    public const array AT_KEYS = ['area', 'col', 'marginX', 'marginY', 'offsetX', 'offsetY'];

    /** @var list<string> */
    public const array TEXT_KEYS = ['kind', 'id', 'text', 'font', 'size', 'color', 'align', 'lineHeight', 'at', 'x', 'y', 'width', 'input'];

    /** @var list<string> */
    public const array TEXT_INPUT_KEYS = ['name', 'maxLength', 'uppercase', 'hidable', 'locked', 'richText', 'sampleValue', 'allowedFonts'];

    /** @var list<string> */
    public const array IMAGE_KEYS = ['kind', 'id', 'asset', 'at', 'x', 'y', 'width', 'height', 'input'];

    /** @var list<string> */
    public const array IMAGE_INPUT_KEYS = ['name', 'placeholder', 'allowMove', 'allowResize', 'allowRotate', 'hidable', 'allowedDirectories'];

    /** @var list<string> */
    public const array SHAPE_KEYS = ['kind', 'id', 'shape', 'fill', 'stroke', 'strokeWidth', 'strokeStyle', 'cornerRadius', 'opacity', 'name', 'locked', 'at', 'x', 'y', 'width', 'height'];

    /**
     * A gradient `fill` object. `angle` is accepted (and ignored) for a radial
     * gradient rather than refused: the canonical wire form emits every key
     * with its resolved value, so a radial element's own `toArray()` has to
     * re-parse.
     *
     * @var list<string>
     */
    public const array SHAPE_FILL_KEYS = ['type', 'angle', 'from', 'to'];

    /** @var list<string> */
    public const array BACKGROUND_KEYS = ['kind', 'id', 'asset', 'fillable'];

    /** @var list<string> */
    public const array CONTAINER_KEYS = ['kind', 'id', 'members', 'children', 'maxHeight', 'gap', 'spaceAfter'];

    /**
     * Slug shape: lowercase only, so two ids can never differ by case alone —
     * `headline` and `Headline` would be distinct keys carrying near-identical
     * meaning, and slug identity is what maps to an existing input's UUID.
     */
    public const string SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';

    public const int MAX_SLUG_LENGTH = 64;

    /** @var list<DslViolation> */
    private array $violations = [];

    /** @var array<string, int> element id => the index that first claimed it */
    private array $seenIds = [];

    private function __construct()
    {
    }

    /**
     * @param mixed $payload the decoded JSON document (associative arrays)
     *
     * @throws InvalidDesignDocument
     */
    public static function parse(mixed $payload): DesignDocument
    {
        return (new self())->run($payload);
    }

    /**
     * Convenience for callers holding the raw string (tests, and any client
     * that hands a JSON-encoded object where the schema asked for an object).
     *
     * @throws InvalidDesignDocument
     */
    public static function parseJson(string $json): DesignDocument
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw InvalidDesignDocument::malformed(
                sprintf('The design is not valid JSON: %s.', $exception->getMessage()),
            );
        }

        return self::parse($decoded);
    }

    /**
     * @throws InvalidDesignDocument
     */
    private function run(mixed $payload): DesignDocument
    {
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw InvalidDesignDocument::malformed(sprintf(
                'The design must be a JSON object with "canvas" and "elements" keys, got %s.',
                self::describe($payload),
            ));
        }

        $this->checkKeys('', $payload, self::ROOT_KEYS, 'the design document');

        $canvas = $this->requirePresent('', $payload, 'canvas')
            ? $this->parseCanvas($payload['canvas'] ?? null)
            : null;

        $elements = $this->parseElements($payload['elements'] ?? null);

        $this->checkDocument($elements, $canvas);

        if ($this->violations !== []) {
            throw InvalidDesignDocument::fromViolations($this->violations);
        }

        // Unreachable for a violation-free parse: every null above records one.
        if ($canvas === null) {
            throw InvalidDesignDocument::malformed('canvas is required.');
        }

        return new DesignDocument($canvas, array_values($elements));
    }

    // -----------------------------------------------------------------
    // canvas
    // -----------------------------------------------------------------

    private function parseCanvas(mixed $raw): null|CanvasSpec
    {
        if (!$this->isObject($raw)) {
            $this->violation('canvas', DslErrorCode::InvalidType, sprintf(
                'canvas must be an object with "width" and "height", got %s.',
                self::describe($raw),
            ));

            return null;
        }

        $this->checkKeys('canvas', $raw, self::CANVAS_KEYS, 'the canvas block');

        $width = $this->requirePresent('canvas', $raw, 'width')
            ? $this->readCanvasSide('canvas', $raw, 'width')
            : null;
        $height = $this->requirePresent('canvas', $raw, 'height')
            ? $this->readCanvasSide('canvas', $raw, 'height')
            : null;

        $backgroundImage = null;
        $backgroundFill = null;
        $background = $raw['background'] ?? null;

        if ($background !== null) {
            if (!$this->isObject($background)) {
                $this->violation('canvas.background', DslErrorCode::InvalidType, sprintf(
                    'canvas.background must be an object with "image" and/or "fill", got %s.',
                    self::describe($background),
                ));
            } else {
                $this->checkKeys('canvas.background', $background, self::CANVAS_BACKGROUND_KEYS, 'the canvas background');
                $backgroundImage = $this->readAssetId('canvas.background', $background, 'image');
                $backgroundFill = $this->readHexColor('canvas.background', $background, 'fill');
            }
        }

        if ($width === null || $height === null) {
            return null;
        }

        return new CanvasSpec($width, $height, $backgroundImage, $backgroundFill);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function readCanvasSide(string $path, array $data, string $key): null|int
    {
        $value = $this->readInt($path, $data, $key);

        if ($value === null) {
            return null;
        }

        if ($value < 1 || $value > CanvasSpec::MAX_SIDE) {
            $this->invalidValue($path, $key, sprintf(
                'must be between 1 and %d canvas pixels, got %d. Print sizes are authored at their 300 DPI raster size (A4 = 2480 x 3508), never in millimetres',
                CanvasSpec::MAX_SIDE,
                $value,
            ));

            return null;
        }

        return $value;
    }

    // -----------------------------------------------------------------
    // elements
    // -----------------------------------------------------------------

    /**
     * @return array<int, DesignElement> keyed by the index in `elements[]`
     */
    private function parseElements(mixed $raw): array
    {
        if ($raw === null) {
            $this->violation('elements', DslErrorCode::MissingKey, 'elements is required (an array of element objects in stack order, bottom to top; use [] for an empty design).');

            return [];
        }

        if (!is_array($raw) || ($raw !== [] && !array_is_list($raw))) {
            $this->violation('elements', DslErrorCode::InvalidType, sprintf(
                'elements must be an array of element objects in stack order (bottom to top), got %s.',
                self::describe($raw),
            ));

            return [];
        }

        $elements = [];

        foreach ($raw as $index => $rawElement) {
            $element = $this->parseElement($index, $rawElement);

            if ($element !== null) {
                $elements[$index] = $element;
            }
        }

        return $elements;
    }

    private function parseElement(int $index, mixed $raw): null|DesignElement
    {
        $path = sprintf('elements[%d]', $index);

        if (!$this->isObject($raw)) {
            $this->violation($path, DslErrorCode::InvalidType, sprintf(
                '%s must be an object with a "kind" key, got %s.',
                $path,
                self::describe($raw),
            ));

            return null;
        }

        $rawKind = $raw['kind'] ?? null;

        if ($rawKind === null) {
            $this->violation(self::join($path, 'kind'), DslErrorCode::MissingKey, sprintf(
                '%s.kind is required. Allowed kinds: %s.',
                $path,
                implode(', ', ElementKind::values()),
            ));

            return null;
        }

        $kind = is_string($rawKind) ? ElementKind::tryFrom($rawKind) : null;

        if ($kind === null) {
            $this->violation(self::join($path, 'kind'), DslErrorCode::InvalidValue, sprintf(
                '%s.kind must be one of: %s. Got %s.',
                $path,
                implode(', ', ElementKind::values()),
                self::describe($rawKind),
            ));

            return null;
        }

        $allowedKeys = match ($kind) {
            ElementKind::Text => self::TEXT_KEYS,
            ElementKind::Image => self::IMAGE_KEYS,
            ElementKind::Shape => self::SHAPE_KEYS,
            ElementKind::Background => self::BACKGROUND_KEYS,
            ElementKind::Container => self::CONTAINER_KEYS,
        };

        $this->checkKeys($path, $raw, $allowedKeys, sprintf('a "%s" element', $kind->value));

        $id = $this->parseId($path, $index, $raw);

        $element = match ($kind) {
            ElementKind::Text => $this->parseTextElement($path, $raw, $id),
            ElementKind::Image => $this->parseImageElement($path, $raw, $id),
            ElementKind::Shape => $this->parseShapeElement($path, $raw, $id),
            ElementKind::Background => $this->parseBackgroundElement($path, $raw, $id),
            ElementKind::Container => $this->parseContainerElement($path, $raw, $id),
        };

        return $element;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function parseTextElement(string $path, array $raw, null|string $id): null|TextElement
    {
        $text = $this->requirePresent($path, $raw, 'text', 'The designed stand-in copy — what the editor shows and what renders when nothing was filled.')
            ? $this->readString($path, $raw, 'text')
            : null;

        $font = $this->requirePresent($path, $raw, 'font', 'An exact face string from get_context, e.g. "Hero New (Hero New ExtraBold)".')
            ? $this->readNonEmptyString($path, $raw, 'font')
            : null;

        $size = $this->requirePresent($path, $raw, 'size', 'The font size in canvas pixels.')
            ? $this->readPositiveNumber($path, $raw, 'size')
            : null;

        $color = $this->readHexColor($path, $raw, 'color') ?? TextElement::DEFAULT_COLOR;
        $align = $this->readTextAlign($path, $raw);
        $lineHeight = $this->readPositiveNumber($path, $raw, 'lineHeight') ?? TextElement::DEFAULT_LINE_HEIGHT;
        $placement = $this->parsePlacement($path, $raw, allowHeight: false);
        $input = $this->parseTextInput($path, $raw['input'] ?? null);

        if ($id === null || $text === null || $font === null || $size === null || $placement === null) {
            return null;
        }

        return new TextElement($id, $text, $font, $size, $color, $align, $lineHeight, $placement, $input);
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function parseImageElement(string $path, array $raw, null|string $id): null|ImageElement
    {
        $assetId = $this->readAssetId($path, $raw, 'asset');
        $placement = $this->parsePlacement($path, $raw, allowHeight: true);
        $input = $this->parseImageInput($path, $raw['input'] ?? null);

        if ($id === null || $placement === null) {
            return null;
        }

        return new ImageElement($id, $assetId, $placement, $input);
    }

    /**
     * A vector shape. Only `shape` is required — everything else has a
     * defensible default, because the commonest authored shape is a flat
     * coloured block and making an agent spell out "no border, square corners,
     * fully opaque" every time is grammar for its own sake.
     *
     * @param array<array-key, mixed> $raw
     */
    private function parseShapeElement(string $path, array $raw, null|string $id): null|ShapeElement
    {
        $shape = $this->requirePresent($path, $raw, 'shape', sprintf('Which shape to draw: %s.', implode(', ', CanvasShapeKind::values())))
            ? $this->readShapeKind($path, $raw)
            : null;

        $fill = $this->readShapeFill($path, $raw) ?? ShapeElement::DEFAULT_FILL;
        $stroke = $this->readHexColor($path, $raw, 'stroke');
        $strokeWidth = $this->readNonNegativeNumber($path, $raw, 'strokeWidth') ?? 0.0;
        $strokeStyle = $this->readShapeStroke($path, $raw);
        $cornerRadius = $this->readNonNegativeNumber($path, $raw, 'cornerRadius') ?? 0.0;
        $opacity = $this->readOpacity($path, $raw);
        $name = $this->readString($path, $raw, 'name');
        $locked = $this->readBool($path, $raw, 'locked', false);
        $placement = $this->parsePlacement($path, $raw, allowHeight: true);

        // Rounding is a rectangle affordance. On an Ellipse (and on the Circle
        // and Polygon that carry no rx/ry at all) Fabric's `rx`/`ry` ARE the
        // radii — honouring a corner radius there would silently resize the
        // shape, so it is refused rather than ignored. Zero always passes: the
        // canonical wire form emits the key for every kind.
        if ($shape !== null && $cornerRadius > 0.0 && !$shape->supportsCornerRadius()) {
            $this->violation(self::join($path, 'cornerRadius'), DslErrorCode::InvalidValue, sprintf(
                '%s.cornerRadius only applies to a %s. A "%s" has no corners to round, so leave it at 0.',
                $path,
                implode(' / ', array_map(
                    static fn (CanvasShapeKind $case): string => $case->value,
                    array_filter(CanvasShapeKind::cases(), static fn (CanvasShapeKind $case): bool => $case->supportsCornerRadius()),
                )),
                $shape->value,
            ));

            return null;
        }

        if ($id === null || $shape === null || $placement === null) {
            return null;
        }

        return new ShapeElement(
            id: $id,
            shape: $shape,
            fill: $fill,
            stroke: $stroke,
            strokeWidth: $strokeWidth,
            strokeStyle: $strokeStyle,
            cornerRadius: $cornerRadius,
            opacity: $opacity,
            name: $name,
            locked: $locked,
            placement: $placement,
        );
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function readShapeKind(string $path, array $raw): null|CanvasShapeKind
    {
        $value = $raw['shape'] ?? null;

        if (!is_string($value)) {
            $this->wrongType($path, 'shape', sprintf('one of: %s', implode(', ', CanvasShapeKind::values())), $value);

            return null;
        }

        $kind = CanvasShapeKind::tryFrom($value);

        if ($kind === null) {
            $this->violation(self::join($path, 'shape'), DslErrorCode::InvalidValue, sprintf(
                '%s.shape must be one of: %s. Got %s.',
                $path,
                implode(', ', CanvasShapeKind::values()),
                self::describe($value),
            ));
        }

        return $kind;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function readShapeStroke(string $path, array $raw): CanvasShapeStroke
    {
        $value = $raw['strokeStyle'] ?? null;

        if ($value === null) {
            return CanvasShapeStroke::Solid;
        }

        if (!is_string($value)) {
            $this->wrongType($path, 'strokeStyle', sprintf('one of: %s', implode(', ', CanvasShapeStroke::values())), $value);

            return CanvasShapeStroke::Solid;
        }

        $style = CanvasShapeStroke::tryFrom($value);

        if ($style === null) {
            $this->violation(self::join($path, 'strokeStyle'), DslErrorCode::InvalidValue, sprintf(
                '%s.strokeStyle must be one of: %s. Got %s.',
                $path,
                implode(', ', CanvasShapeStroke::values()),
                self::describe($value),
            ));

            return CanvasShapeStroke::Solid;
        }

        return $style;
    }

    /**
     * `opacity` is a fraction, not a percentage — the same 0…1 Fabric stores,
     * so nothing has to convert on either side of the round-trip.
     *
     * @param array<array-key, mixed> $raw
     */
    private function readOpacity(string $path, array $raw): float
    {
        $value = $this->readNonNegativeNumber($path, $raw, 'opacity');

        if ($value === null) {
            return 1.0;
        }

        if ($value > 1.0) {
            $this->violation(self::join($path, 'opacity'), DslErrorCode::InvalidValue, sprintf(
                '%s.opacity is a fraction between 0 and 1 (0.6 = 60 %% opaque), not a percentage. Got %s.',
                $path,
                self::number($value),
            ));

            return 1.0;
        }

        return $value;
    }

    /**
     * A shape's fill: either a hex colour string or a two-stop gradient object.
     *
     * Deliberately NOT checked against the project's brand palette. The
     * editor's own swatches are suggestions and its picker takes any colour, so
     * a grammar that refused one would be stricter than the UI it describes —
     * off-brand fills are a lint (`ColorNotInPalette`), not a parse error.
     *
     * @param array<array-key, mixed> $raw
     */
    private function readShapeFill(string $path, array $raw): null|string|CanvasShapeGradient
    {
        $value = $raw['fill'] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $this->readHexColor($path, $raw, 'fill');
        }

        if (!self::isObject($value)) {
            $this->wrongType($path, 'fill', 'a hex colour string like "#c8102e", or a gradient object {type, from, to, angle}', $value);

            return null;
        }

        $fillPath = self::join($path, 'fill');
        $this->checkKeys($fillPath, $value, self::SHAPE_FILL_KEYS, 'a gradient fill');

        $type = $value['type'] ?? null;

        if (!is_string($type) || !in_array($type, [CanvasShapeGradient::TYPE_LINEAR, CanvasShapeGradient::TYPE_RADIAL], true)) {
            $this->violation(self::join($fillPath, 'type'), DslErrorCode::InvalidValue, sprintf(
                '%s.type must be "%s" or "%s". Got %s.',
                $fillPath,
                CanvasShapeGradient::TYPE_LINEAR,
                CanvasShapeGradient::TYPE_RADIAL,
                self::describe($type),
            ));

            return null;
        }

        $from = $this->requirePresent($fillPath, $value, 'from', 'The gradient\'s first colour, as a hex string.')
            ? $this->readHexColor($fillPath, $value, 'from')
            : null;

        $to = $this->requirePresent($fillPath, $value, 'to', 'The gradient\'s second colour, as a hex string.')
            ? $this->readHexColor($fillPath, $value, 'to')
            : null;

        if ($from === null || $to === null) {
            return null;
        }

        // A radial gradient is centre-out, so an authored angle would be a
        // silent no-op. Pinning it keeps `toArray()` canonical.
        $angle = $type === CanvasShapeGradient::TYPE_RADIAL
            ? 90.0
            : $this->readGradientAngle($fillPath, $value);

        return new CanvasShapeGradient($type, $angle, $from, $to);
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function readGradientAngle(string $path, array $raw): float
    {
        $angle = $this->readNumber($path, $raw, 'angle');

        if ($angle === null) {
            // Top→bottom: the direction a reader's eye already travels, and the
            // one the editor's own picker starts on.
            return 90.0;
        }

        if ($angle < 0.0 || $angle >= 360.0) {
            $this->violation(self::join($path, 'angle'), DslErrorCode::InvalidValue, sprintf(
                '%s.angle is in degrees, 0 (left to right) up to but not including 360, clockwise. Got %s.',
                $path,
                self::number($angle),
            ));

            return 90.0;
        }

        return $angle;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function parseBackgroundElement(string $path, array $raw, null|string $id): null|BackgroundElement
    {
        $assetId = $this->readAssetId($path, $raw, 'asset');
        $fillable = $this->readBool($path, $raw, 'fillable', false);

        if ($id === null) {
            return null;
        }

        return new BackgroundElement($id, $assetId, $fillable);
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function parseContainerElement(string $path, array $raw, null|string $id): null|ContainerElement
    {
        $members = $this->readIdList($path, $raw, 'members');
        $children = $this->readIdList($path, $raw, 'children');
        $maxHeight = $this->readPositiveNumber($path, $raw, 'maxHeight');
        $gap = $this->readNonNegativeNumber($path, $raw, 'gap');
        $spaceAfter = $this->readNonNegativeNumber($path, $raw, 'spaceAfter');

        if ($id === null) {
            return null;
        }

        return new ContainerElement($id, $members, $children, $maxHeight, $gap, $spaceAfter);
    }

    // -----------------------------------------------------------------
    // placement
    // -----------------------------------------------------------------

    /**
     * @param array<array-key, mixed> $raw
     */
    private function parsePlacement(string $path, array $raw, bool $allowHeight): null|Placement
    {
        $rawAt = $raw['at'] ?? null;
        $at = $rawAt === null ? null : $this->parseSemanticPlacement($path . '.at', $rawAt);

        $x = $this->readNumber($path, $raw, 'x');
        $y = $this->readNumber($path, $raw, 'y');
        $width = $this->readPositiveNumber($path, $raw, 'width');
        $height = $allowHeight ? $this->readPositiveNumber($path, $raw, 'height') : null;

        if ($rawAt === null) {
            $missing = [];

            foreach (['x', 'y', 'width'] as $key) {
                if (($raw[$key] ?? null) === null) {
                    $missing[] = $key;
                }
            }

            if ($missing !== []) {
                $this->violation($path, DslErrorCode::MissingKey, sprintf(
                    '%s has no resolvable placement: give it "at" (semantic placement, preferred - it adapts to every canvas size), or all of "x", "y" and "width" in canvas pixels. Missing: %s.',
                    $path,
                    implode(', ', $missing),
                ));

                return null;
            }
        }

        return new Placement($at, $x, $y, $width, $height);
    }

    private function parseSemanticPlacement(string $path, mixed $raw): null|SemanticPlacement
    {
        if (!$this->isObject($raw)) {
            $this->violation($path, DslErrorCode::InvalidType, sprintf(
                '%s must be an object, e.g. {"area": "top", "col": [1, 12]}. Got %s.',
                $path,
                self::describe($raw),
            ));

            return null;
        }

        $this->checkKeys($path, $raw, self::AT_KEYS, 'a semantic placement');

        $area = null;
        $rawArea = $raw['area'] ?? null;

        if ($rawArea === null) {
            $this->violation(self::join($path, 'area'), DslErrorCode::MissingKey, sprintf(
                '%s.area is required. Allowed areas: %s.',
                $path,
                implode(', ', PlacementArea::values()),
            ));
        } else {
            $area = is_string($rawArea) ? PlacementArea::tryFrom($rawArea) : null;

            if ($area === null) {
                $this->violation(self::join($path, 'area'), DslErrorCode::InvalidValue, sprintf(
                    '%s.area must be one of: %s. Got %s.',
                    $path,
                    implode(', ', PlacementArea::values()),
                    self::describe($rawArea),
                ));
            }
        }

        $columns = $this->readColumnSpan($path, $raw);
        $marginX = $this->readNonNegativeNumber($path, $raw, 'marginX') ?? 0.0;
        $marginY = $this->readNonNegativeNumber($path, $raw, 'marginY') ?? 0.0;
        $offsetX = $this->readNumber($path, $raw, 'offsetX') ?? 0.0;
        $offsetY = $this->readNumber($path, $raw, 'offsetY') ?? 0.0;

        if ($area === null || $columns === null) {
            return null;
        }

        return new SemanticPlacement($area, $columns[0], $columns[1], $marginX, $marginY, $offsetX, $offsetY);
    }

    /**
     * @param array<array-key, mixed> $raw
     * @return null|array{int, int}
     */
    private function readColumnSpan(string $path, array $raw): null|array
    {
        $rawCol = $raw['col'] ?? null;

        if ($rawCol === null) {
            return [1, SemanticPlacement::GRID_COLUMNS];
        }

        $shape = sprintf(
            '%s.col must be an array of two column numbers on the %d-column grid, e.g. [1, %d]',
            $path,
            SemanticPlacement::GRID_COLUMNS,
            SemanticPlacement::GRID_COLUMNS,
        );

        if (!is_array($rawCol) || !array_is_list($rawCol) || count($rawCol) !== 2) {
            $this->violation(self::join($path, 'col'), DslErrorCode::InvalidValue, sprintf(
                '%s. Got %s.',
                $shape,
                self::describe($rawCol),
            ));

            return null;
        }

        $start = $this->readColumn($path, 0, $rawCol[0]);
        $end = $this->readColumn($path, 1, $rawCol[1]);

        if ($start === null || $end === null) {
            return null;
        }

        if ($start > $end) {
            $this->violation(self::join($path, 'col'), DslErrorCode::InvalidValue, sprintf(
                '%s.col starts at column %d and ends at column %d - the start column must not be greater than the end column.',
                $path,
                $start,
                $end,
            ));

            return null;
        }

        return [$start, $end];
    }

    private function readColumn(string $path, int $position, mixed $value): null|int
    {
        $columnPath = sprintf('%s.col[%d]', $path, $position);

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        if (!is_int($value)) {
            $this->violation($columnPath, DslErrorCode::InvalidType, sprintf(
                '%s must be a whole column number between 1 and %d, got %s.',
                $columnPath,
                SemanticPlacement::GRID_COLUMNS,
                self::describe($value),
            ));

            return null;
        }

        if ($value < 1 || $value > SemanticPlacement::GRID_COLUMNS) {
            $this->violation($columnPath, DslErrorCode::InvalidValue, sprintf(
                '%s must be between 1 and %d, got %d.',
                $columnPath,
                SemanticPlacement::GRID_COLUMNS,
                $value,
            ));

            return null;
        }

        return $value;
    }

    // -----------------------------------------------------------------
    // input blocks
    // -----------------------------------------------------------------

    private function parseTextInput(string $path, mixed $raw): TextInputSpec
    {
        if ($raw === null) {
            return new TextInputSpec();
        }

        $inputPath = $path . '.input';

        if (!$this->isObject($raw)) {
            $this->violation($inputPath, DslErrorCode::InvalidType, sprintf(
                '%s must be an object, got %s.',
                $inputPath,
                self::describe($raw),
            ));

            return new TextInputSpec();
        }

        $this->checkKeys($inputPath, $raw, self::TEXT_INPUT_KEYS, 'a text input block');

        $maxLength = $this->readInt($inputPath, $raw, 'maxLength');

        if ($maxLength !== null && $maxLength < 1) {
            $this->invalidValue($inputPath, 'maxLength', sprintf('must be at least 1 character, got %d', $maxLength));
            $maxLength = null;
        }

        return new TextInputSpec(
            $this->readString($inputPath, $raw, 'name'),
            $maxLength,
            $this->readBool($inputPath, $raw, 'uppercase', false),
            $this->readBool($inputPath, $raw, 'hidable', false),
            $this->readBool($inputPath, $raw, 'locked', false),
            $this->readBool($inputPath, $raw, 'richText', false),
            $this->readString($inputPath, $raw, 'sampleValue'),
            $this->readFontList($inputPath, $raw, 'allowedFonts'),
        );
    }

    /**
     * A list of exact face strings — shape only; whether each names a real
     * project face is the compiler's call (the parser is context-free).
     *
     * @param array<array-key, mixed> $raw
     * @return list<string>
     */
    private function readFontList(string $path, array $raw, string $key): array
    {
        $value = $raw[$key] ?? null;

        if ($value === null) {
            return [];
        }

        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            $this->violation(self::join($path, $key), DslErrorCode::InvalidType, sprintf(
                '%s.%s must be an array of face strings (as get_context lists them), got %s.',
                $path,
                $key,
                self::describe($value),
            ));

            return [];
        }

        $families = [];

        foreach ($value as $position => $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                $this->violation(sprintf('%s.%s[%d]', $path, $key, $position), DslErrorCode::InvalidType, sprintf(
                    '%s.%s[%d] must be a face string, got %s.',
                    $path,
                    $key,
                    $position,
                    self::describe($entry),
                ));

                continue;
            }

            if (!in_array($entry, $families, true)) {
                $families[] = $entry;
            }
        }

        return $families;
    }

    private function parseImageInput(string $path, mixed $raw): null|ImageInputSpec
    {
        if ($raw === null) {
            return null;
        }

        $inputPath = $path . '.input';

        if (!$this->isObject($raw)) {
            $this->violation($inputPath, DslErrorCode::InvalidType, sprintf(
                '%s must be an object, got %s. Omit it entirely for a decorative image.',
                $inputPath,
                self::describe($raw),
            ));

            return null;
        }

        $this->checkKeys($inputPath, $raw, self::IMAGE_INPUT_KEYS, 'an image input block');

        return new ImageInputSpec(
            $this->readString($inputPath, $raw, 'name'),
            $this->readBool($inputPath, $raw, 'placeholder', true),
            $this->readBool($inputPath, $raw, 'allowMove', true),
            $this->readBool($inputPath, $raw, 'allowResize', true),
            $this->readBool($inputPath, $raw, 'allowRotate', false),
            $this->readBool($inputPath, $raw, 'hidable', false),
            $this->readDirectoryIds($inputPath, $raw),
        );
    }

    /**
     * @param array<array-key, mixed> $raw
     * @return list<string>
     */
    private function readDirectoryIds(string $path, array $raw): array
    {
        $rawList = $raw['allowedDirectories'] ?? null;

        if ($rawList === null) {
            return [];
        }

        if (!is_array($rawList) || ($rawList !== [] && !array_is_list($rawList))) {
            $this->violation(self::join($path, 'allowedDirectories'), DslErrorCode::InvalidType, sprintf(
                '%s.allowedDirectories must be an array of gallery folder ids (UUIDs from list_gallery); [] means the whole gallery is offered. Got %s.',
                $path,
                self::describe($rawList),
            ));

            return [];
        }

        $ids = [];

        foreach ($rawList as $position => $value) {
            if (!is_string($value) || !Uuid::isValid($value)) {
                $this->violation(sprintf('%s.allowedDirectories[%d]', $path, $position), DslErrorCode::InvalidValue, sprintf(
                    '%s.allowedDirectories[%d] must be a gallery folder id (a UUID as returned by list_gallery), got %s.',
                    $path,
                    $position,
                    self::describe($value),
                ));

                continue;
            }

            $ids[] = $value;
        }

        return $ids;
    }

    // -----------------------------------------------------------------
    // document-level checks
    // -----------------------------------------------------------------

    /**
     * @param array<int, DesignElement> $elements
     */
    private function checkDocument(array $elements, null|CanvasSpec $canvas): void
    {
        $this->checkSingleBackground($elements, $canvas);
        $this->checkContainers($elements);
    }

    /**
     * @param array<int, DesignElement> $elements
     */
    private function checkSingleBackground(array $elements, null|CanvasSpec $canvas): void
    {
        $firstIndex = null;

        foreach ($elements as $index => $element) {
            if (!$element instanceof BackgroundElement) {
                continue;
            }

            if ($firstIndex === null) {
                $firstIndex = $index;

                continue;
            }

            $this->violation(sprintf('elements[%d]', $index), DslErrorCode::InvalidStructure, sprintf(
                'elements[%d] is a second "background" element; a document may declare at most one (it compiles to the single background layer at the bottom of the stack). The first one is elements[%d].',
                $index,
                $firstIndex,
            ));
        }

        if ($firstIndex !== null && $canvas?->backgroundImageAssetId !== null) {
            $this->violation('canvas.background.image', DslErrorCode::InvalidStructure, sprintf(
                'canvas.background.image and the "background" element at elements[%d] both define the background layer - use one or the other. (canvas.background.fill is a flat colour and may be combined with a background element.)',
                $firstIndex,
            ));
        }
    }

    /**
     * @param array<int, DesignElement> $elements
     */
    private function checkContainers(array $elements): void
    {
        /** @var array<string, DesignElement> $byId */
        $byId = [];
        /** @var array<string, int> $indexById */
        $indexById = [];

        foreach ($elements as $index => $element) {
            $byId[$element->id] = $element;
            $indexById[$element->id] = $index;
        }

        /** @var array<string, ContainerElement> $containersById */
        $containersById = [];

        foreach ($elements as $element) {
            if ($element instanceof ContainerElement) {
                $containersById[$element->id] = $element;
            }
        }

        /** @var array<string, string> $parentOf child id => parent id */
        $parentOf = [];

        foreach ($elements as $index => $container) {
            if (!$container instanceof ContainerElement) {
                continue;
            }

            $path = sprintf('elements[%d]', $index);

            $this->checkContainerMembers($path, $container, $byId);
            $this->checkContainerChildren($path, $container, $byId, $containersById, $parentOf);

            if (count($container->referencedIds()) < 2) {
                $this->violation($path, DslErrorCode::InvalidStructure, sprintf(
                    '%s ("%s") groups %d item(s); a container needs at least 2 (members + children). A single-item container reflows nothing and is dropped by the canvas sanitizer, so it would silently disappear from the saved design.',
                    $path,
                    $container->id,
                    count($container->referencedIds()),
                ));
            }
        }

        $this->checkContainerCycles($containersById, $indexById);
        $this->checkRootContainerBounds($containersById, $indexById, $parentOf);
    }

    /**
     * @param array<string, DesignElement> $byId
     */
    private function checkContainerMembers(string $path, ContainerElement $container, array $byId): void
    {
        $seen = [];

        foreach ($container->memberIds as $position => $memberId) {
            $memberPath = sprintf('%s.members[%d]', $path, $position);

            if (isset($seen[$memberId])) {
                $this->violation($memberPath, DslErrorCode::InvalidStructure, sprintf(
                    '%s.members lists "%s" twice.',
                    $path,
                    $memberId,
                ));

                continue;
            }

            $seen[$memberId] = true;

            if ($memberId === $container->id) {
                $this->violation($memberPath, DslErrorCode::InvalidStructure, sprintf(
                    '%s.members contains the container\'s own id "%s".',
                    $path,
                    $memberId,
                ));

                continue;
            }

            $member = $byId[$memberId] ?? null;

            if ($member === null) {
                $this->violation($memberPath, DslErrorCode::UnknownReference, sprintf(
                    '%s.members references "%s", which no element declares.',
                    $path,
                    $memberId,
                ));

                continue;
            }

            if ($member instanceof ContainerElement) {
                $this->violation($memberPath, DslErrorCode::InvalidStructure, sprintf(
                    '%s.members references the container "%s". Nest a container by listing it in "children", not in "members".',
                    $path,
                    $memberId,
                ));

                continue;
            }

            if ($member instanceof BackgroundElement) {
                $this->violation($memberPath, DslErrorCode::InvalidStructure, sprintf(
                    '%s.members references the background layer "%s". The background is never a container member - it covers the whole canvas and does not flow.',
                    $path,
                    $memberId,
                ));

                continue;
            }

            if ($member instanceof ImageElement && $member->isPlaceholder()) {
                $this->violation($memberPath, DslErrorCode::InvalidStructure, sprintf(
                    '%s.members references "%s", which is a fillable image placeholder. Only texts, shapes and DECORATIVE images can flow in a container; drop its "input" block or move it out of the container.',
                    $path,
                    $memberId,
                ));
            }
        }
    }

    /**
     * @param array<string, DesignElement> $byId
     * @param array<string, ContainerElement> $containersById
     * @param array<string, string> $parentOf
     */
    private function checkContainerChildren(
        string $path,
        ContainerElement $container,
        array $byId,
        array $containersById,
        array &$parentOf,
    ): void {
        $seen = [];

        foreach ($container->childIds as $position => $childId) {
            $childPath = sprintf('%s.children[%d]', $path, $position);

            if (isset($seen[$childId])) {
                $this->violation($childPath, DslErrorCode::InvalidStructure, sprintf(
                    '%s.children lists "%s" twice.',
                    $path,
                    $childId,
                ));

                continue;
            }

            $seen[$childId] = true;

            if ($childId === $container->id) {
                $this->violation($childPath, DslErrorCode::InvalidStructure, sprintf(
                    '%s.children contains the container\'s own id "%s" - a container cannot be nested inside itself.',
                    $path,
                    $childId,
                ));

                continue;
            }

            if (!isset($containersById[$childId])) {
                $code = isset($byId[$childId]) ? DslErrorCode::InvalidStructure : DslErrorCode::UnknownReference;
                $this->violation($childPath, $code, isset($byId[$childId])
                    ? sprintf('%s.children references "%s", which is not a container. List texts and decorative images in "members" instead.', $path, $childId)
                    : sprintf('%s.children references "%s", which no element declares.', $path, $childId));

                continue;
            }

            if (isset($parentOf[$childId])) {
                $this->violation($childPath, DslErrorCode::InvalidStructure, sprintf(
                    '%s.children references "%s", which is already nested in "%s". A container has exactly one parent.',
                    $path,
                    $childId,
                    $parentOf[$childId],
                ));

                continue;
            }

            $parentOf[$childId] = $container->id;
        }
    }

    /**
     * @param array<string, ContainerElement> $containersById
     * @param array<string, int> $indexById
     */
    private function checkContainerCycles(array $containersById, array $indexById): void
    {
        /** @var array<string, int> $state 1 = on the stack, 2 = finished */
        $state = [];
        /** @var list<string> $stack */
        $stack = [];
        /** @var array<string, true> $reported */
        $reported = [];

        foreach (array_keys($containersById) as $id) {
            if (($state[$id] ?? 0) === 0) {
                $this->visitContainer($id, $containersById, $indexById, $state, $stack, $reported);
            }
        }
    }

    /**
     * @param array<string, ContainerElement> $containersById
     * @param array<string, int> $indexById
     * @param array<string, int> $state
     * @param list<string> $stack
     * @param array<string, true> $reported
     */
    private function visitContainer(
        string $id,
        array $containersById,
        array $indexById,
        array &$state,
        array &$stack,
        array &$reported,
    ): void {
        $container = $containersById[$id] ?? null;

        if ($container === null) {
            return;
        }

        $state[$id] = 1;
        $stack[] = $id;

        foreach ($container->childIds as $childId) {
            if ($childId === $id || !isset($containersById[$childId])) {
                continue;
            }

            $childState = $state[$childId] ?? 0;

            if ($childState === 1) {
                $start = array_search($childId, $stack, true);
                $cycle = $start === false ? [$childId] : array_slice($stack, $start);
                $cycle[] = $childId;
                $signature = implode(' -> ', $cycle);

                if (!isset($reported[$signature])) {
                    $reported[$signature] = true;
                    $index = $indexById[$id] ?? 0;
                    $this->violation(sprintf('elements[%d]', $index), DslErrorCode::InvalidStructure, sprintf(
                        'elements[%d] closes a container cycle: %s. Nested containers must form a tree.',
                        $index,
                        $signature,
                    ));
                }

                continue;
            }

            if ($childState === 0) {
                $this->visitContainer($childId, $containersById, $indexById, $state, $stack, $reported);
            }
        }

        array_pop($stack);
        $state[$id] = 2;
    }

    /**
     * A ROOT container (one nobody nests) must carry `maxHeight`: it is the
     * bound that gates overflow and the only one the strict export reports on.
     * A nested container may omit it - its height is not a bound (plan §4.4).
     *
     * @param array<string, ContainerElement> $containersById
     * @param array<string, int> $indexById
     * @param array<string, string> $parentOf
     */
    private function checkRootContainerBounds(array $containersById, array $indexById, array $parentOf): void
    {
        foreach ($containersById as $id => $container) {
            if (isset($parentOf[$id]) || $container->maxHeight !== null) {
                continue;
            }

            $index = $indexById[$id] ?? 0;
            $this->violation(sprintf('elements[%d].maxHeight', $index), DslErrorCode::MissingKey, sprintf(
                'elements[%d].maxHeight is required for the top-level container "%s" - it is the flow bound that gates overflow. Only a container nested in another one may omit it.',
                $index,
                $id,
            ));
        }
    }

    // -----------------------------------------------------------------
    // scalar readers
    // -----------------------------------------------------------------

    /**
     * @param array<array-key, mixed> $raw
     */
    private function parseId(string $path, int $index, array $raw): null|string
    {
        if (!$this->requirePresent($path, $raw, 'id', 'A short, stable slug that identifies this element across set_design calls.')) {
            return null;
        }

        $value = $raw['id'] ?? null;

        if (!is_string($value)) {
            $this->wrongType($path, 'id', 'a string', $value);

            return null;
        }

        if (preg_match(self::SLUG_PATTERN, $value) !== 1 || strlen($value) > self::MAX_SLUG_LENGTH) {
            $this->invalidValue($path, 'id', sprintf(
                'must be a slug of at most %d characters: lowercase letters, digits, "-" and "_", starting with a letter or digit (e.g. "headline"). Got %s',
                self::MAX_SLUG_LENGTH,
                self::describe($value),
            ));

            return null;
        }

        if (isset($this->seenIds[$value])) {
            $this->violation(self::join($path, 'id'), DslErrorCode::DuplicateId, sprintf(
                '%s.id "%s" is already used by elements[%d]. Element ids must be unique - they are what carries input identity across set_design calls.',
                $path,
                $value,
                $this->seenIds[$value],
            ));

            return null;
        }

        $this->seenIds[$value] = $index;

        return $value;
    }

    /**
     * @param array<array-key, mixed> $raw
     * @return list<string>
     */
    private function readIdList(string $path, array $raw, string $key): array
    {
        $value = $raw[$key] ?? null;

        if ($value === null) {
            return [];
        }

        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            $this->violation(self::join($path, $key), DslErrorCode::InvalidType, sprintf(
                '%s.%s must be an array of element ids, got %s.',
                $path,
                $key,
                self::describe($value),
            ));

            return [];
        }

        $ids = [];

        foreach ($value as $position => $entry) {
            if (!is_string($entry) || $entry === '') {
                $this->violation(sprintf('%s.%s[%d]', $path, $key, $position), DslErrorCode::InvalidType, sprintf(
                    '%s.%s[%d] must be an element id, got %s.',
                    $path,
                    $key,
                    $position,
                    self::describe($entry),
                ));

                continue;
            }

            $ids[] = $entry;
        }

        return $ids;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function readString(string $path, array $raw, string $key): null|string
    {
        $value = $raw[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            $this->wrongType($path, $key, 'a string', $value);

            return null;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function readNonEmptyString(string $path, array $raw, string $key): null|string
    {
        $value = $this->readString($path, $raw, $key);

        if ($value === null) {
            return null;
        }

        if (trim($value) === '') {
            $this->invalidValue($path, $key, 'must not be empty');

            return null;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function readBool(string $path, array $raw, string $key, bool $default): bool
    {
        $value = $raw[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (!is_bool($value)) {
            $this->wrongType($path, $key, 'a boolean (true or false)', $value);

            return $default;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function readNumber(string $path, array $raw, string $key): null|float
    {
        $value = $raw[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_float($value) && is_finite($value)) {
            return $value;
        }

        $this->wrongType($path, $key, 'a number', $value);

        return null;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function readPositiveNumber(string $path, array $raw, string $key): null|float
    {
        $value = $this->readNumber($path, $raw, $key);

        if ($value === null) {
            return null;
        }

        if ($value <= 0.0) {
            $this->invalidValue($path, $key, sprintf('must be greater than 0, got %s', self::number($value)));

            return null;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function readNonNegativeNumber(string $path, array $raw, string $key): null|float
    {
        $value = $this->readNumber($path, $raw, $key);

        if ($value === null) {
            return null;
        }

        if ($value < 0.0) {
            $this->invalidValue($path, $key, sprintf('must not be negative, got %s', self::number($value)));

            return null;
        }

        return $value;
    }

    /**
     * Accepts an integer, or a float with no fractional part (an agent writing
     * `1080.0` means 1080). Anything else is reported.
     *
     * @param array<array-key, mixed> $raw
     */
    private function readInt(string $path, array $raw, string $key): null|int
    {
        $value = $raw[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value)) {
            if (floor($value) === $value && abs($value) < (float) PHP_INT_MAX) {
                return (int) $value;
            }

            $this->invalidValue($path, $key, sprintf('must be a whole number, got %s', self::number($value)));

            return null;
        }

        $this->wrongType($path, $key, 'a whole number', $value);

        return null;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function readTextAlign(string $path, array $raw): TextAlign
    {
        $value = $raw['align'] ?? null;

        if ($value === null) {
            return TextAlign::Left;
        }

        $align = is_string($value) ? TextAlign::tryFrom($value) : null;

        if ($align === null) {
            $this->violation(self::join($path, 'align'), DslErrorCode::InvalidValue, sprintf(
                '%s.align must be one of: %s. Got %s.',
                $path,
                implode(', ', TextAlign::values()),
                self::describe($value),
            ));

            return TextAlign::Left;
        }

        return $align;
    }

    /**
     * Hex colour, normalized to lowercase `#rrggbb` through the app-wide
     * {@see RichText::normalizeHexColor()} — one implementation, so a colour
     * the fill page accepts is a colour the DSL accepts. `#fff` shorthand is
     * accepted and expanded; alpha channels are not supported.
     *
     * @param array<array-key, mixed> $raw
     */
    private function readHexColor(string $path, array $raw, string $key): null|string
    {
        $value = $raw[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            $this->wrongType($path, $key, 'a hex colour string like "#c8102e"', $value);

            return null;
        }

        $normalized = RichText::normalizeHexColor($value);

        if ($normalized === null) {
            $this->invalidValue($path, $key, sprintf('must be a hex colour like "#c8102e" or "#fff", got %s', self::describe($value)));

            return null;
        }

        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function readAssetId(string $path, array $raw, string $key): null|string
    {
        $value = $raw[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value) || !Uuid::isValid($value)) {
            $this->violation(self::join($path, $key), DslErrorCode::InvalidValue, sprintf(
                '%s.%s must be a gallery image id (a UUID as returned by list_gallery or upload_image), not a file name or a URL. Got %s.',
                $path,
                $key,
                self::describe($value),
            ));

            return null;
        }

        return $value;
    }

    // -----------------------------------------------------------------
    // violation plumbing
    // -----------------------------------------------------------------

    /**
     * @param array<array-key, mixed> $data
     */
    private function requirePresent(string $path, array $data, string $key, string $hint = ''): bool
    {
        if (($data[$key] ?? null) !== null) {
            return true;
        }

        $full = self::join($path, $key);
        $this->violation($full, DslErrorCode::MissingKey, rtrim(sprintf('%s is required. %s', $full, $hint)));

        return false;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string> $allowed
     */
    private function checkKeys(string $path, array $data, array $allowed, string $subject): void
    {
        foreach (array_keys($data) as $rawKey) {
            $key = (string) $rawKey;

            if (in_array($key, $allowed, true)) {
                continue;
            }

            $suggestion = self::suggest($key, $allowed);
            $full = self::join($path, $key);

            $this->violation($full, DslErrorCode::UnknownKey, sprintf(
                '%s is not a valid key for %s.%s Allowed keys: %s.',
                $full,
                $subject,
                $suggestion === null ? '' : sprintf(' Did you mean "%s"?', $suggestion),
                implode(', ', $allowed),
            ));
        }
    }

    private function wrongType(string $path, string $key, string $expected, mixed $value): void
    {
        $full = self::join($path, $key);
        $this->violation($full, DslErrorCode::InvalidType, sprintf(
            '%s must be %s, got %s.',
            $full,
            $expected,
            self::describe($value),
        ));
    }

    private function invalidValue(string $path, string $key, string $requirement): void
    {
        $full = self::join($path, $key);
        $this->violation($full, DslErrorCode::InvalidValue, sprintf('%s %s.', $full, $requirement));
    }

    private function violation(string $path, DslErrorCode $code, string $message): void
    {
        $this->violations[] = new DslViolation($path, $code, $message);
    }

    /**
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    private function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    private static function join(string $path, string $key): string
    {
        return $path === '' ? $key : $path . '.' . $key;
    }

    /**
     * Nearest valid key, or null when nothing is close enough.
     *
     * Plain edit distance is not enough for the mistakes agents actually make:
     * `fontSize` -> `size` is 4 edits away, so a distance threshold generous
     * enough to catch it would suggest nonsense everywhere else. Hence the
     * ladder: case-insensitive exact (a case typo), then suffix
     * (`fontSize`->`size`, `textAlign`->`align`), then prefix
     * (`fontFamily`->`font`), and only then Levenshtein for real typos
     * (`colour`->`color`), with a threshold scaled to the key's length so
     * short keys cannot be confused with each other (`at` never suggests `id`).
     *
     * @param list<string> $allowed
     */
    private static function suggest(string $unknown, array $allowed): null|string
    {
        $needle = strtolower($unknown);

        if ($needle === '') {
            return null;
        }

        foreach ($allowed as $candidate) {
            if (strtolower($candidate) === $needle) {
                return $candidate;
            }
        }

        foreach ([true, false] as $suffixPass) {
            $best = null;
            $bestLength = 0;

            foreach ($allowed as $candidate) {
                $lower = strtolower($candidate);
                $matches = $suffixPass
                    ? str_ends_with($needle, $lower)
                    : str_starts_with($needle, $lower);

                if (strlen($lower) >= 3 && $lower !== $needle && $matches && strlen($lower) > $bestLength) {
                    $best = $candidate;
                    $bestLength = strlen($lower);
                }
            }

            if ($best !== null) {
                return $best;
            }
        }

        $threshold = max(1, intdiv(strlen($needle), 3));
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($allowed as $candidate) {
            $distance = levenshtein($needle, strtolower($candidate));

            if ($distance <= $threshold && $distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    private static function describe(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return 'a boolean (' . ($value ? 'true' : 'false') . ')';
        }

        if (is_int($value) || is_float($value)) {
            return 'a number (' . self::number($value) . ')';
        }

        if (is_string($value)) {
            return 'a string ("' . self::truncate($value) . '")';
        }

        if (is_array($value)) {
            return ($value === [] || !array_is_list($value)) ? 'an object' : 'an array';
        }

        return 'a ' . get_debug_type($value);
    }

    private static function number(int|float $value): string
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < (float) PHP_INT_MAX) {
            return (string) (int) $value;
        }

        return (string) $value;
    }

    private static function truncate(string $value): string
    {
        $collapsed = str_replace(["\n", "\r", "\t"], ' ', $value);

        return mb_strlen($collapsed) > 40
            ? mb_substr($collapsed, 0, 40) . '...'
            : $collapsed;
    }
}
