<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * The size of ONE template variant.
 *
 * Field for field this is {@see ContextDimensionResponse} without its
 * `variantCount` — deliberately, so an agent that learned "this project designs
 * at 1:1 and A4" from `get_context` reads the very same keys back on the
 * variants `find_templates` returns, and never has to translate between two
 * spellings of one concept.
 *
 * `unit`/`unitWidth`/`unitHeight` is what the designer authored (A4 is
 * "210 × 297 mm"); `width`/`height` are the canvas PIXELS every coordinate
 * downstream is expressed in. Physical units rasterize at 300 DPI, so the two
 * are not interchangeable and must never be converted by hand.
 *
 * `preset` is non-null only for the fixed social formats (`1:1`, `4:5`,
 * `9:16`) — which is also the flag that says a variant may be published
 * straight to Facebook/Instagram.
 *
 * `printFormat` (mm / cm) says the variant also exports a print PDF
 * (`export_variant` format "pdf"). Its bleed is printed and trimmed off:
 * `bleedPx` per side, in the same canvas pixels — artwork meant to reach the
 * paper edge must extend past the canvas by that much (negative x/y, or
 * width/height + bleedPx). 0 for screen formats.
 */
readonly final class VariantDimensionResponse
{
    public function __construct(
        public string $label,
        public null|string $preset,
        public string $unit,
        public float $unitWidth,
        public float $unitHeight,
        public int $width,
        public int $height,
        public bool $printFormat = false,
        public float $bleedMm = 0.0,
        public int $bleedPx = 0,
        public bool $cropMarks = false,
    ) {
    }
}
