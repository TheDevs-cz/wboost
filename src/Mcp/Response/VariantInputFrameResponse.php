<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * Where a placeholder sits on the canvas: an axis-aligned rectangle in canvas
 * PIXELS with a top-left origin — the same space as the variant's
 * `dimension.width`/`height`, so mapping it onto a rendered image is one scale
 * factor and never a unit conversion.
 *
 * v1 limitation, stated so nobody debugs it twice: a ROTATED placeholder is
 * reported as its upright bounding box, which is larger than the drawn shape.
 *
 * For a text input this is the DESIGNED box. Text inside a container moves at
 * render time when a filled text above it wraps to more lines — the frame is
 * where the design puts it, not where a particular fill ends up.
 */
readonly final class VariantInputFrameResponse
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {
    }
}
