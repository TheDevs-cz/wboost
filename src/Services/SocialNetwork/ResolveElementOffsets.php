<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\InvalidExportValue;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\CanvasShape;
use WBoost\Web\Value\ElementMoveAllowance;
use WBoost\Web\Value\ElementOffsets;
use WBoost\Web\Value\MovableElement;

/**
 * "Uživatel může posunout" (user-movable elements, phase M2) — validates the
 * user's element offsets against the design and returns the map the renderer
 * bakes ({@see \WBoost\Web\Value\ResolvedInputOverrides::$offsets}):
 * element id → `{x, y}` in canvas px of the rendered variant, relative to the
 * designed position.
 *
 * **Eligibility** ({@see movableElements()}, the single source): an element
 * may move when it carries a `userMove` allowance AND it is
 * - a free text (locked ones too), a free shape or a free decorative image —
 *   never the background layer, never an image placeholder (its "Posun
 *   obrázku v rámečku" pans the picture instead), never a container member
 *   (the CONTAINER moves; a member's own allowance is inert), never
 *   design-hidden; or
 * - a TOP-LEVEL container (a nested one — repeatable rows and their
 *   materialised slots included — is a member, so inert), moved as one
 *   block: its whole deep tree.
 *
 * **Frames.** The allowance's "unlimited = stays on the artwork" bound is
 * measured from the DESIGNED frame: Fabric's `getBoundingRect()` of the
 * object (stroke included, rotation-aware); for a container the union of its
 * design-visible deep members' boxes. Measured on the canvas the render uses
 * — i.e. the EXPANDED variant ({@see \WBoost\Web\Services\Template\RepeatableRows}):
 * a container holding a repeatable row is measured with all `max` rows
 * materialised, so "unlimited" keeps it on the artwork whatever the row
 * count. The artwork is the variant's TRIM box (`dimension` width × height —
 * canvas coordinates are trim-relative even in a print pass), so for print
 * an unlimited move never reaches into the bleed or the crop-mark band;
 * an explicit maximum is the designer's word and is trusted.
 *
 * **Modes** (the same contract as the shape-colour resolver):
 * - lenient (web fill, preview, stored versions, the renderer's belt):
 *   clamp each axis into its range, a disallowed axis → 0, ineligible /
 *   unknown ids and non-numeric values dropped;
 * - strict (API / MCP — wired in phase M5): structured 400s
 *   `unknown_element`, `move_not_allowed`, `offset_out_of_range`
 *   ({@see InvalidExportValue}).
 *
 * Output is canonical: values rounded to 1/100 px
 * ({@see ElementMoveAllowance::round2()}), zero offsets omitted, keys sorted
 * — what the state hash, the cache key and the export versions rely on.
 */
final readonly class ResolveElementOffsets
{
    public const string REASON_NOT_ALLOWED = 'not_allowed';
    public const string REASON_IN_CONTAINER = 'in_container';
    public const string REASON_HIDDEN = 'hidden';

    /** Strict mode tolerates float noise of a client that sends the exact bound. */
    private const float RANGE_TOLERANCE_PX = 0.005;

    private const array TEXT_TYPES = ['textbox', 'text', 'i-text', 'itext'];

    /**
     * @param array<array-key, mixed> $provided element id → `{x?, y?}` (numbers or numeric strings)
     *
     * @return array<string, array{x: float, y: float}>
     *
     * @throws InvalidExportValue strict mode only
     */
    public static function forVariant(TemplateVariant $variant, array $provided, bool $lenient): array
    {
        if ($provided === []) {
            return [];
        }

        return self::resolve(
            self::stampedCanvas($variant),
            (float) $variant->dimension->width(),
            (float) $variant->dimension->height(),
            $provided,
            $lenient,
        );
    }

    /**
     * Every movable element of a variant (the fill pages' mirrors, the seeders).
     *
     * @return array<string, MovableElement>
     */
    public static function movableElementsOf(TemplateVariant $variant): array
    {
        if (!str_contains($variant->canvas, '"' . ElementMoveAllowance::CANVAS_KEY . '"')) {
            return []; // cheap pre-check — the common case decodes nothing
        }

        return self::movableElements(self::stampedCanvas($variant));
    }

    /**
     * @param array<array-key, mixed> $canvas decoded canvas, textbox inputIds stamped
     * @param array<array-key, mixed> $provided
     *
     * @return array<string, array{x: float, y: float}>
     *
     * @throws InvalidExportValue strict mode only
     */
    public static function resolve(array $canvas, float $canvasWidth, float $canvasHeight, array $provided, bool $lenient): array
    {
        if ($provided === []) {
            return [];
        }

        $refused = [];
        $movable = self::movableElements($canvas, $refused);
        $resolved = [];

        foreach ($provided as $rawId => $rawOffset) {
            $id = (string) $rawId;
            $x = self::coordinate(is_array($rawOffset) ? ($rawOffset['x'] ?? null) : null);
            $y = self::coordinate(is_array($rawOffset) ? ($rawOffset['y'] ?? null) : null);

            if ($x === null || $y === null || !is_array($rawOffset)) {
                if ($lenient) {
                    continue;
                }

                throw InvalidExportValue::invalidValue(sprintf('The offset of element "%s" must be {"x": number, "y": number}.', $id))
                    ->withContext(['elementId' => $id]);
            }

            $element = $movable[$id] ?? null;

            if ($element === null) {
                if ($lenient || ($x == 0.0 && $y == 0.0)) {
                    continue;
                }

                if (isset($refused[$id])) {
                    throw InvalidExportValue::moveNotAllowed($id, $refused[$id]);
                }

                throw InvalidExportValue::unknownElement($id);
            }

            if (!$lenient) {
                self::assertWithinRange($element, $x, $y, $canvasWidth, $canvasHeight);
            }

            $clamped = $element->clamp($x, $y, $canvasWidth, $canvasHeight);

            if ($clamped['x'] != 0.0 || $clamped['y'] != 0.0) {
                $resolved[$id] = $clamped;
            }
        }

        ksort($resolved, SORT_STRING);

        return $resolved;
    }

    /**
     * The renderer's belt: an override map that did NOT come from this
     * resolver's lenient pass (or did, against another design revision) is
     * clamped against the canvas actually rendered, then baked. Idempotent
     * on an already resolved map.
     *
     * @template TCanvas of array<array-key, mixed>
     *
     * @param TCanvas $canvas decoded canvas, textbox inputIds stamped
     * @param array<string, array{x: float, y: float}> $offsets
     *
     * @return TCanvas
     */
    public static function bake(array $canvas, float $canvasWidth, float $canvasHeight, array $offsets): array
    {
        if ($offsets === []) {
            return $canvas;
        }

        $clamped = self::resolve($canvas, $canvasWidth, $canvasHeight, $offsets, lenient: true);

        if ($clamped === []) {
            return $canvas;
        }

        /** @var TCanvas $baked only left / top values change */
        $baked = ElementOffsets::bake($canvas, $clamped);

        return $baked;
    }

    /**
     * The single eligibility source: id → movable element. `$refused` (out)
     * collects every addressable element that may NOT move, with the reason —
     * the strict mode's `move_not_allowed` vs `unknown_element` distinction.
     *
     * @param array<array-key, mixed> $canvas decoded canvas, textbox inputIds stamped
     * @param array<string, string> $refused
     *
     * @param-out array<string, self::REASON_*> $refused
     *
     * @return array<string, MovableElement>
     */
    public static function movableElements(array $canvas, array &$refused = []): array
    {
        $refused = [];
        $containers = CanvasContainer::collectionFromCanvas($canvas);
        $objects = is_array($canvas['objects'] ?? null) ? $canvas['objects'] : [];

        $memberIds = [];
        foreach ($containers as $container) {
            foreach ($container->memberInputIds as $memberId) {
                $memberIds[$memberId] = true;
            }
        }

        /** @var array<string, array<array-key, mixed>> $objectsById first object per inputId */
        $objectsById = [];
        $movable = [];

        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }
            $id = $object['inputId'] ?? null;
            if (!is_string($id) || $id === '' || isset($objectsById[$id])) {
                continue;
            }
            $objectsById[$id] = $object;

            $kind = self::objectKind($object);
            $allowance = ElementMoveAllowance::fromCanvasObject($object);

            if (isset($memberIds[$id])) {
                $refused[$id] = self::REASON_IN_CONTAINER;
            } elseif (($object['visible'] ?? true) === false) {
                $refused[$id] = self::REASON_HIDDEN;
            } elseif ($kind === null || $allowance === null) {
                $refused[$id] = self::REASON_NOT_ALLOWED;
            } else {
                $frame = self::objectFrame($object);
                if ($frame === null) {
                    $refused[$id] = self::REASON_NOT_ALLOWED;
                } else {
                    $movable[$id] = new MovableElement($id, $kind, $allowance, $frame);
                }
            }
        }

        foreach ($containers as $container) {
            if (isset($movable[$container->id]) || isset($refused[$container->id])) {
                continue; // an id clash with an object — the object wins (bake keys objects first)
            }

            if ($container->isNestedIn($containers)) {
                $refused[$container->id] = self::REASON_IN_CONTAINER;
                continue;
            }

            if ($container->userMove === null) {
                $refused[$container->id] = self::REASON_NOT_ALLOWED;
                continue;
            }

            $frame = null;
            foreach ($container->deepMemberInputIds($containers) as $memberId) {
                $object = $objectsById[$memberId] ?? null;
                if ($object === null || ($object['visible'] ?? true) === false) {
                    continue;
                }
                $box = self::objectFrame($object);
                if ($box !== null) {
                    $frame = $frame === null ? $box : self::union($frame, $box);
                }
            }

            if ($frame === null) {
                $refused[$container->id] = self::REASON_HIDDEN;
                continue;
            }

            $movable[$container->id] = new MovableElement($container->id, MovableElement::KIND_CONTAINER, $container->userMove, $frame);
        }

        ksort($movable, SORT_STRING);

        return $movable;
    }

    /**
     * Fabric's `getBoundingRect()` of a plain canvas-JSON object: the stroke
     * is part of the box (added before scaling, or after it for
     * `strokeUniform`), the box is rotated about the origin anchor
     * (`left`/`top` sit at `originX`/`originY`). Skew is ignored (the editor
     * never sets it). Null when the geometry is missing.
     *
     * @param array<array-key, mixed> $object
     *
     * @return null|array{left: float, top: float, width: float, height: float}
     */
    public static function objectFrame(array $object): null|array
    {
        $width = self::number($object['width'] ?? null);
        $height = self::number($object['height'] ?? null);
        if ($width === null || $height === null || $width < 0.0 || $height < 0.0) {
            return null;
        }

        $scaleX = self::number($object['scaleX'] ?? null) ?? 1.0;
        $scaleY = self::number($object['scaleY'] ?? null) ?? 1.0;
        $stroke = max(0.0, self::number($object['strokeWidth'] ?? null) ?? 0.0);
        $uniform = ($object['strokeUniform'] ?? false) === true;

        $w = abs($uniform ? $width * $scaleX + $stroke : ($width + $stroke) * $scaleX);
        $h = abs($uniform ? $height * $scaleY + $stroke : ($height + $stroke) * $scaleY);

        $ox = self::originOffset($object['originX'] ?? null, $w, ['left' => 0.0, 'center' => 0.5, 'right' => 1.0]);
        $oy = self::originOffset($object['originY'] ?? null, $h, ['top' => 0.0, 'center' => 0.5, 'bottom' => 1.0]);

        $left = self::number($object['left'] ?? null) ?? 0.0;
        $top = self::number($object['top'] ?? null) ?? 0.0;
        $rad = deg2rad(self::number($object['angle'] ?? null) ?? 0.0);
        $cos = cos($rad);
        $sin = sin($rad);

        $minX = $minY = PHP_FLOAT_MAX;
        $maxX = $maxY = -PHP_FLOAT_MAX;
        foreach ([[-$ox, -$oy], [$w - $ox, -$oy], [-$ox, $h - $oy], [$w - $ox, $h - $oy]] as [$cx, $cy]) {
            $x = $left + $cx * $cos - $cy * $sin;
            $y = $top + $cx * $sin + $cy * $cos;
            $minX = min($minX, $x);
            $maxX = max($maxX, $x);
            $minY = min($minY, $y);
            $maxY = max($maxY, $y);
        }

        return ['left' => $minX, 'top' => $minY, 'width' => $maxX - $minX, 'height' => $maxY - $minY];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function stampedCanvas(TemplateVariant $variant): array
    {
        $canvas = json_decode($variant->canvas, true);
        if (!is_array($canvas) || !is_array($canvas['objects'] ?? null)) {
            return ['objects' => []];
        }

        $objects = $canvas['objects'];
        foreach (TextInputObjectBinder::positionalBinding($canvas, $variant->inputs) as $index => $inputId) {
            if (is_array($objects[$index] ?? null)) {
                $objects[$index]['inputId'] = $inputId;
            }
        }
        $canvas['objects'] = $objects;

        return $canvas;
    }

    /**
     * @throws InvalidExportValue
     */
    private static function assertWithinRange(MovableElement $element, float $x, float $y, float $canvasWidth, float $canvasHeight): void
    {
        $bounds = $element->bounds($canvasWidth, $canvasHeight);

        foreach (['x' => [$x, $bounds['minX'], $bounds['maxX'], $element->allowance->horizontal], 'y' => [$y, $bounds['minY'], $bounds['maxY'], $element->allowance->vertical]] as $axis => [$value, $min, $max, $allowed]) {
            if ($value == 0.0) {
                continue;
            }
            if (!$allowed) {
                throw InvalidExportValue::moveNotAllowed($element->id, self::REASON_NOT_ALLOWED, $axis);
            }
            if ($value < $min - self::RANGE_TOLERANCE_PX || $value > $max + self::RANGE_TOLERANCE_PX) {
                throw InvalidExportValue::offsetOutOfRange($element->id, $axis, $min, $max, ElementMoveAllowance::round2($value));
            }
        }
    }

    /**
     * @param array<array-key, mixed> $object
     */
    private static function objectKind(array $object): null|string
    {
        $type = is_string($object['type'] ?? null) ? strtolower($object['type']) : '';

        if (in_array($type, self::TEXT_TYPES, true)) {
            return MovableElement::KIND_TEXT;
        }

        if (CanvasShape::isShapeType($type)) {
            return MovableElement::KIND_SHAPE;
        }

        if ($type === 'image') {
            // The background layer never moves; a placeholder's frame is
            // fixed ("Posun obrázku v rámečku" pans the picture inside it).
            if (($object['isBackground'] ?? false) === true || ($object['imagePlaceholder'] ?? false) === true) {
                return null;
            }

            return MovableElement::KIND_IMAGE;
        }

        return null;
    }

    /**
     * A finite number, a numeric string, or "" / null (= 0). Null = invalid.
     */
    private static function coordinate(mixed $value): null|float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_string($value)) {
            $value = trim($value);
            if (!is_numeric($value)) {
                return null;
            }
            $value = (float) $value;
        }

        if (!is_int($value) && !is_float($value)) {
            return null;
        }

        $value = (float) $value;

        return is_finite($value) ? $value : null;
    }

    private static function number(mixed $value): null|float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }

        return is_finite((float) $value) ? (float) $value : null;
    }

    /**
     * @param array<string, float> $named
     */
    private static function originOffset(mixed $origin, float $size, array $named): float
    {
        if (is_string($origin) && isset($named[$origin])) {
            return $size * $named[$origin];
        }

        $fraction = self::number($origin);

        return $fraction === null ? 0.0 : $size * $fraction;
    }

    /**
     * @param array{left: float, top: float, width: float, height: float} $a
     * @param array{left: float, top: float, width: float, height: float} $b
     *
     * @return array{left: float, top: float, width: float, height: float}
     */
    private static function union(array $a, array $b): array
    {
        $left = min($a['left'], $b['left']);
        $top = min($a['top'], $b['top']);

        return [
            'left' => $left,
            'top' => $top,
            'width' => max($a['left'] + $a['width'], $b['left'] + $b['width']) - $left,
            'height' => max($a['top'] + $a['height'], $b['top'] + $b['height']) - $top,
        ];
    }
}
