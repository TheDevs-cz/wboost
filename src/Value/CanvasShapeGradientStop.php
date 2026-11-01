<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One colour stop of a {@see CanvasShapeGradient}.
 */
readonly final class CanvasShapeGradientStop
{
    public function __construct(
        /** Position along the gradient, 0…1, rounded to whole percent. */
        public float $offset,
        /** Lowercase `#rrggbb`. */
        public string $color,
        /** 0 (transparent) … 1 (opaque), rounded to whole percent. */
        public float $opacity = 1.0,
    ) {
    }

    public static function create(float $offset, string $color, float $opacity = 1.0): self
    {
        return new self(self::roundUnit($offset), strtolower($color), self::roundUnit($opacity));
    }

    public function isOpaque(): bool
    {
        return $this->opacity >= 1.0;
    }

    /**
     * Clamp to 0…1 and round to whole percent — the editor UI's resolution,
     * mirrored by `roundUnit` in `assets/controllers/canvas_shapes.js`.
     */
    public static function roundUnit(float $value): float
    {
        return round(min(1.0, max(0.0, $value)) * 100) / 100;
    }
}
