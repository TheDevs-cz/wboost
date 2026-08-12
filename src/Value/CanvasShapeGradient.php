<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A two-stop gradient fill for a canvas shape — the PHP mirror of
 * `buildGradient` / `describeFill` in `assets/controllers/canvas_shapes.js`.
 *
 * **`gradientUnits` is always `percentage`**, i.e. coordinates are 0…1 of the
 * object's own bounding box. That is load-bearing rather than stylistic: a
 * pixel-unit gradient is baked to whatever size the shape had when it was
 * authored and visibly slides off under every rescale the object will see —
 * the designer resizing it, the group projector fanning it into another
 * dimension, and the export rendering it at print resolution.
 *
 * Two stops only. Fabric supports arbitrarily many, and the canvas JSON would
 * carry them fine, but the editor UI authors exactly two — exposing more
 * through the DSL would create designs the designer cannot then edit.
 */
readonly final class CanvasShapeGradient
{
    public const string TYPE_LINEAR = 'linear';
    public const string TYPE_RADIAL = 'radial';

    public function __construct(
        /** self::TYPE_LINEAR | self::TYPE_RADIAL */
        public string $type,
        /**
         * Degrees, 0 = left→right, 90 = top→bottom (screen axes, y down).
         * Meaningless for a radial gradient, where it is pinned to 90 so the
         * canonical wire form stays stable across a round-trip.
         */
        public float $angle,
        /** Lowercase `#rrggbb`. */
        public string $from,
        /** Lowercase `#rrggbb`. */
        public string $to,
    ) {
    }

    public function isRadial(): bool
    {
        return $this->type === self::TYPE_RADIAL;
    }

    /**
     * The Fabric `fill` block, byte-compatible with what the editor's
     * `buildGradient` produces.
     *
     * @return array<string, mixed>
     */
    public function toFabric(): array
    {
        $colorStops = [
            ['offset' => 0, 'color' => $this->from],
            ['offset' => 1, 'color' => $this->to],
        ];

        if ($this->isRadial()) {
            return [
                'type' => self::TYPE_RADIAL,
                'gradientUnits' => 'percentage',
                'coords' => ['x1' => 0.5, 'y1' => 0.5, 'r1' => 0, 'x2' => 0.5, 'y2' => 0.5, 'r2' => 0.5],
                'colorStops' => $colorStops,
                'offsetX' => 0,
                'offsetY' => 0,
            ];
        }

        $radians = deg2rad($this->angle);
        $dx = cos($radians) / 2;
        $dy = sin($radians) / 2;

        return [
            'type' => self::TYPE_LINEAR,
            'gradientUnits' => 'percentage',
            'coords' => ['x1' => 0.5 - $dx, 'y1' => 0.5 - $dy, 'x2' => 0.5 + $dx, 'y2' => 0.5 + $dy],
            'colorStops' => $colorStops,
            'offsetX' => 0,
            'offsetY' => 0,
        ];
    }

    /**
     * Read a canvas object's `fill` back into a gradient, or null when it is a
     * flat colour (or anything this VO cannot faithfully represent).
     *
     * Deliberately lenient about the stop COUNT — a canvas that acquired a
     * multi-stop gradient from somewhere else still decompiles, keeping its
     * first and last stops, rather than dropping the whole element. The lint
     * is where "you will lose the middle stops" belongs, not the reader.
     */
    public static function fromFabric(mixed $fill): null|self
    {
        if (!is_array($fill)) {
            return null;
        }

        $stops = $fill['colorStops'] ?? null;

        if (!is_array($stops) || $stops === []) {
            return null;
        }

        $sorted = array_values($stops);
        usort($sorted, static function (mixed $a, mixed $b): int {
            $left = is_array($a) && is_numeric($a['offset'] ?? null) ? (float) $a['offset'] : 0.0;
            $right = is_array($b) && is_numeric($b['offset'] ?? null) ? (float) $b['offset'] : 0.0;

            return $left <=> $right;
        });

        $first = $sorted[0];
        $last = $sorted[count($sorted) - 1];

        $from = is_array($first) && is_string($first['color'] ?? null) ? $first['color'] : null;
        $to = is_array($last) && is_string($last['color'] ?? null) ? $last['color'] : null;

        if ($from === null || $to === null) {
            return null;
        }

        $radial = ($fill['type'] ?? null) === self::TYPE_RADIAL;
        $coords = $fill['coords'] ?? null;

        return new self(
            type: $radial ? self::TYPE_RADIAL : self::TYPE_LINEAR,
            angle: $radial ? 90.0 : self::angleFromCoords(is_array($coords) ? $coords : []),
            from: strtolower($from),
            to: strtolower($to),
        );
    }

    /**
     * @param array<array-key, mixed> $coords
     */
    private static function angleFromCoords(array $coords): float
    {
        $num = static fn (string $key): float => is_numeric($coords[$key] ?? null) ? (float) $coords[$key] : 0.0;

        $dx = $num('x2') - $num('x1');
        $dy = $num('y2') - $num('y1');

        if ($dx === 0.0 && $dy === 0.0) {
            return 90.0;
        }

        $degrees = round(rad2deg(atan2($dy, $dx)));

        return (float) ((((int) $degrees % 360) + 360) % 360);
    }

    /**
     * Canonical DSL wire form.
     *
     * @return array{type: string, angle: float, from: string, to: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'angle' => $this->angle,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}
