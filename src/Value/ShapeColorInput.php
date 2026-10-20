<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A vector shape the designer opened up for RECOLOURING — "Uživatel může měnit
 * barvu" in the shape popover. The end user (fill page, API, MCP) may then
 * repaint the shape's fill, border or both in one colour from the admin's list
 * (or any colour, when the admin allowed that).
 *
 * Unlike {@see EditorTextInput} / {@see EditorImageInput} there is NO column
 * behind this: the whole definition lives on the canvas object as custom
 * properties (`colorChoice`, `allowedColors`, `colorFree`, plus the `inputId`
 * every shape already carries), and this class is a read-side view derived
 * from the canvas on demand. That keeps the save payload, the copy handlers,
 * group sync and the MCP compiler on their existing paths — a shape is still
 * just a canvas object — and makes it impossible for a separate list to drift
 * from the design.
 *
 * Eligibility is decided HERE, once, for every consumer (renderer, fill pages,
 * API listing, resolver): the shape must be visible and the painted part must
 * be a SOLID colour. A gradient fill cannot be recoloured with one pick (yet),
 * and a border with no width paints nothing, so `both` degrades to whichever
 * part is paintable and a shape with neither is simply not offered.
 */
final readonly class ShapeColorInput
{
    /**
     * @param list<string> $allowedColors lowercase `#rrggbb` swatches the admin
     *        picked (manual colours + custom ones), in the admin's order
     */
    public function __construct(
        public string $inputId,
        public null|string $name,
        public ShapeColorTarget $target,
        public array $allowedColors,
        /** "Libovolná barva": any hex goes, the list is only a suggestion. */
        public bool $freeColor,
        /** The designed colour of the painted part (fill first) — always allowed. */
        public string $defaultColor,
    ) {
    }

    /**
     * Every colour the user may pick when the free picker is off: the designed
     * colour first (the "reset" swatch), then the admin's list.
     *
     * @return list<string>
     */
    public function colorOptions(): array
    {
        return array_values(array_unique([$this->defaultColor, ...$this->allowedColors]));
    }

    /**
     * @param string $color normalized lowercase `#rrggbb`
     */
    public function allows(string $color): bool
    {
        return $this->freeColor || in_array($color, $this->colorOptions(), true);
    }

    /**
     * @return list<self> in canvas (stack) order, bottom first
     */
    public static function collectionFromCanvasJson(string $canvasJson): array
    {
        $canvas = json_decode($canvasJson, true);

        return is_array($canvas) ? self::collectionFromCanvas($canvas) : [];
    }

    /**
     * @param array<array-key, mixed> $canvas decoded canvas JSON
     * @return list<self>
     */
    public static function collectionFromCanvas(array $canvas): array
    {
        $objects = $canvas['objects'] ?? null;
        if (!is_array($objects)) {
            return [];
        }

        $inputs = [];
        $seen = [];

        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }

            $input = self::fromObject($object);

            // Shared inputIds only ever happen across DIMENSIONS of a group;
            // inside one canvas a duplicate (a paste the editor failed to
            // re-mint) must not become two fields for one id.
            if ($input !== null && !isset($seen[$input->inputId])) {
                $seen[$input->inputId] = true;
                $inputs[] = $input;
            }
        }

        return $inputs;
    }

    /**
     * The recolourable view of ONE canvas object, or null when it is not a
     * shape, is not opened up, is design-hidden, or has nothing paintable.
     *
     * @param array<array-key, mixed> $object
     */
    public static function fromObject(array $object): null|self
    {
        $type = $object['type'] ?? null;
        if (!CanvasShape::isShapeType(is_string($type) ? $type : null)) {
            return null;
        }

        if (($object['visible'] ?? true) === false) {
            return null;
        }

        $inputId = $object['inputId'] ?? null;
        $declared = is_string($object['colorChoice'] ?? null) ? ShapeColorTarget::tryFrom($object['colorChoice']) : null;
        if (!is_string($inputId) || $inputId === '' || $declared === null) {
            return null;
        }

        $fill = self::solidColor($object['fill'] ?? null);
        $strokeWidth = $object['strokeWidth'] ?? 0;
        $stroke = (is_int($strokeWidth) || is_float($strokeWidth)) && $strokeWidth > 0
            ? self::solidColor($object['stroke'] ?? null)
            : null;

        $target = self::effectiveTarget($declared, $fill !== null, $stroke !== null);
        if ($target === null) {
            return null;
        }

        $allowed = [];
        $rawAllowed = $object['allowedColors'] ?? null;
        foreach (is_array($rawAllowed) ? $rawAllowed : [] as $color) {
            $normalized = is_string($color) ? RichText::normalizeHexColor($color) : null;
            if ($normalized !== null && !in_array($normalized, $allowed, true)) {
                $allowed[] = $normalized;
            }
        }

        $name = $object['name'] ?? null;

        return new self(
            inputId: $inputId,
            name: is_string($name) && trim($name) !== '' ? $name : null,
            target: $target,
            allowedColors: $allowed,
            freeColor: ($object['colorFree'] ?? false) === true,
            defaultColor: $target->paintsFill() ? (string) $fill : (string) $stroke,
        );
    }

    /**
     * Repaint a canvas object in a picked colour — the parts the shape's
     * EFFECTIVE target names, nothing else. The caller has already validated
     * the colour against {@see allows()}.
     *
     * @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    public function paint(array $object, string $color): array
    {
        if ($this->target->paintsFill()) {
            $object['fill'] = $color;
        }

        if ($this->target->paintsStroke()) {
            $object['stroke'] = $color;
        }

        return $object;
    }

    private static function effectiveTarget(ShapeColorTarget $declared, bool $hasFill, bool $hasStroke): null|ShapeColorTarget
    {
        return match (true) {
            $declared === ShapeColorTarget::Both && $hasFill && $hasStroke => ShapeColorTarget::Both,
            $declared->paintsFill() && $hasFill => ShapeColorTarget::Fill,
            $declared->paintsStroke() && $hasStroke => ShapeColorTarget::Stroke,
            default => null,
        };
    }

    /**
     * A Fabric paint value as `#rrggbb` — null for a gradient (an object),
     * `transparent`, an empty string or anything else that is not one hex
     * colour.
     */
    private static function solidColor(mixed $paint): null|string
    {
        return is_string($paint) ? RichText::normalizeHexColor($paint) : null;
    }
}
