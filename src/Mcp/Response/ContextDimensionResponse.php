<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * One dimension a project already designs at.
 *
 * Both faces of the size are exposed on purpose: `unit`/`unitWidth`/`unitHeight`
 * is what the designer authored (A4 is "210 × 297 mm"), while `width`/`height`
 * are the canvas PIXELS everything downstream works in — placeholder frames,
 * the design DSL's coordinates and the exported image are all in that space.
 * Physical units rasterize at 300 DPI, so the two are not interchangeable and
 * an agent must never convert between them itself.
 *
 * `preset` is non-null only for the fixed social formats (`1:1`, `4:5`, `9:16`);
 * it is also the flag that says a variant of this size can be published
 * straight to Facebook/Instagram.
 *
 * `unitWidth`/`unitHeight` are floats here but reach the client as plain JSON
 * numbers — a whole value serializes as `210`, not `210.0` (PHP drops the zero
 * fraction and the SDK owns the encode flags). JSON has one number type, so
 * nothing is lost; it is only worth knowing before writing an equality test.
 */
readonly final class ContextDimensionResponse
{
    public function __construct(
        public string $label,
        public null|string $preset,
        public string $unit,
        public float $unitWidth,
        public float $unitHeight,
        public int $width,
        public int $height,
        public int $variantCount,
    ) {
    }
}
