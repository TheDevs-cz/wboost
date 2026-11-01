<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A gradient fill for a canvas shape — the PHP mirror of `buildGradient` /
 * `describeFill` in `assets/controllers/canvas_shapes.js`.
 *
 * **`gradientUnits` is always `percentage`**, i.e. coordinates are 0…1 of the
 * object's own bounding box. That is load-bearing rather than stylistic: a
 * pixel-unit gradient is baked to whatever size the shape had when it was
 * authored and visibly slides off under every rescale the object will see —
 * the designer resizing it, the group projector fanning it into another
 * dimension, and the export rendering it at print resolution.
 *
 * 2…{@see self::MAX_STOPS} stops, each with its own opacity (a white
 * 0 % → 100 % fade is the classic overlay over a photo). The opacity rides
 * INSIDE the Fabric stop colour as `rgba(r,g,b,a)` — Fabric's renderer passes
 * `color` straight to `CanvasGradient.addColorStop` and ignores a separate
 * `opacity` key — and that string is byte-identical to the editor's
 * `gradientStopColor` (see {@see self::stopColor()}).
 */
readonly final class CanvasShapeGradient
{
    public const string TYPE_LINEAR = 'linear';
    public const string TYPE_RADIAL = 'radial';

    /** Mirrored by `GRADIENT_MAX_STOPS` in canvas_shapes.js. */
    public const int MAX_STOPS = 8;

    /** @var non-empty-list<CanvasShapeGradientStop> sorted by offset */
    public array $stops;

    /**
     * @param list<CanvasShapeGradientStop> $stops at least two
     */
    public function __construct(
        /** self::TYPE_LINEAR | self::TYPE_RADIAL */
        public string $type,
        /**
         * Degrees, 0 = left→right, 90 = top→bottom (screen axes, y down).
         * Meaningless for a radial gradient, where it is pinned to 90 so the
         * canonical wire form stays stable across a round-trip.
         */
        public float $angle,
        array $stops,
    ) {
        if (count($stops) < 2) {
            throw new \InvalidArgumentException('A gradient needs at least two stops.');
        }

        usort($stops, static fn (CanvasShapeGradientStop $a, CanvasShapeGradientStop $b): int => $a->offset <=> $b->offset);
        $this->stops = $stops;
    }

    /** The classic opaque two-colour gradient. */
    public static function twoStop(string $type, float $angle, string $from, string $to): self
    {
        return new self($type, $angle, [
            CanvasShapeGradientStop::create(0.0, $from),
            CanvasShapeGradientStop::create(1.0, $to),
        ]);
    }

    public function isRadial(): bool
    {
        return $this->type === self::TYPE_RADIAL;
    }

    public function from(): string
    {
        return $this->stops[0]->color;
    }

    public function to(): string
    {
        return $this->stops[count($this->stops) - 1]->color;
    }

    /**
     * Two opaque stops at 0 and 1 — what the DSL words as `{from, to}`.
     */
    public function isSimpleTwoStop(): bool
    {
        return count($this->stops) === 2
            && $this->stops[0]->offset === 0.0
            && $this->stops[1]->offset === 1.0
            && $this->stops[0]->isOpaque()
            && $this->stops[1]->isOpaque();
    }

    /**
     * The Fabric `fill` block, byte-compatible with what the editor's
     * `buildGradient` produces.
     *
     * @return array<string, mixed>
     */
    public function toFabric(): array
    {
        $colorStops = array_map(
            static fn (CanvasShapeGradientStop $stop): array => [
                'offset' => self::compactNumber($stop->offset),
                'color' => self::stopColor($stop),
            ],
            $this->stops,
        );

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
     * `#rrggbb` when opaque, `rgba(r,g,b,a)` otherwise — the exact string
     * `gradientStopColor` in canvas_shapes.js writes.
     */
    public static function stopColor(CanvasShapeGradientStop $stop): string
    {
        if ($stop->isOpaque()) {
            return $stop->color;
        }

        return sprintf(
            'rgba(%d,%d,%d,%s)',
            (int) hexdec(substr($stop->color, 1, 2)),
            (int) hexdec(substr($stop->color, 3, 2)),
            (int) hexdec(substr($stop->color, 5, 2)),
            (string) $stop->opacity,
        );
    }

    /**
     * Read a canvas object's `fill` back into a gradient, or null when it is a
     * flat colour (or anything this VO cannot faithfully represent: a stop
     * colour that is neither hex nor rgb[a], or more than MAX_STOPS stops).
     */
    public static function fromFabric(mixed $fill): null|self
    {
        if (!is_array($fill)) {
            return null;
        }

        $rawStops = $fill['colorStops'] ?? null;

        if (!is_array($rawStops) || count($rawStops) < 2 || count($rawStops) > self::MAX_STOPS) {
            return null;
        }

        $stops = [];

        foreach ($rawStops as $rawStop) {
            if (!is_array($rawStop) || !is_string($rawStop['color'] ?? null)) {
                return null;
            }

            $parsed = self::parseColor($rawStop['color']);

            if ($parsed === null) {
                return null;
            }

            [$hex, $alpha] = $parsed;
            // An SVG-imported stop may carry its own `opacity`; fold it in.
            $extraOpacity = is_numeric($rawStop['opacity'] ?? null) ? (float) $rawStop['opacity'] : 1.0;
            $offset = is_numeric($rawStop['offset'] ?? null) ? (float) $rawStop['offset'] : 0.0;

            $stops[] = CanvasShapeGradientStop::create($offset, $hex, $alpha * $extraOpacity);
        }

        $radial = ($fill['type'] ?? null) === self::TYPE_RADIAL;
        $coords = $fill['coords'] ?? null;

        return new self(
            type: $radial ? self::TYPE_RADIAL : self::TYPE_LINEAR,
            angle: $radial ? 90.0 : self::angleFromCoords(is_array($coords) ? $coords : []),
            stops: $stops,
        );
    }

    /**
     * Hex (`#rgb` / `#rrggbb`) or `rgb()` / `rgba()` → [`#rrggbb`, alpha].
     *
     * @return null|array{string, float}
     */
    private static function parseColor(string $value): null|array
    {
        $hex = RichText::normalizeHexColor($value);

        if ($hex !== null) {
            return [$hex, 1.0];
        }

        if (preg_match('/^rgba?\(\s*([0-9.]+)\s*,\s*([0-9.]+)\s*,\s*([0-9.]+)\s*(?:,\s*([0-9.]+)\s*)?\)$/i', trim($value), $matches) !== 1) {
            return null;
        }

        $channel = static fn (string $raw): int => max(0, min(255, (int) round((float) $raw)));

        return [
            sprintf('#%02x%02x%02x', $channel($matches[1]), $channel($matches[2]), $channel($matches[3])),
            isset($matches[4]) ? (float) $matches[4] : 1.0,
        ];
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

    /** 0 / 1 as integers (as the editor serializes them), fractions as floats. */
    private static function compactNumber(float $value): int|float
    {
        return floor($value) === $value ? (int) $value : $value;
    }

    /**
     * Canonical DSL wire form: `{type, angle, from, to}` for a plain opaque
     * two-colour gradient (the form every design authored before multi-stop
     * support uses), `{type, angle, stops: [{at, color, opacity}]}` otherwise.
     *
     * @return array{type: string, angle: float, from: string, to: string}|array{type: string, angle: float, stops: list<array{at: float, color: string, opacity: float}>}
     */
    public function toArray(): array
    {
        if ($this->isSimpleTwoStop()) {
            return [
                'type' => $this->type,
                'angle' => $this->angle,
                'from' => $this->from(),
                'to' => $this->to(),
            ];
        }

        return [
            'type' => $this->type,
            'angle' => $this->angle,
            'stops' => array_map(
                static fn (CanvasShapeGradientStop $stop): array => [
                    'at' => $stop->offset,
                    'color' => $stop->color,
                    'opacity' => $stop->opacity,
                ],
                $this->stops,
            ),
        ];
    }
}
