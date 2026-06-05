<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A single image placeholder's resolved fill: the chosen picture already inlined
 * as a base64 data URI (so the headless Gotenberg render needs no Minio access),
 * its natural pixel size, and the validated frame-relative transform. The
 * renderer combines this with the placeholder's {@see PlaceholderFrame} to
 * compute the absolute Fabric placement.
 */
readonly final class ResolvedImageOverride
{
    public function __construct(
        public string $dataUri,
        public int $naturalWidth,
        public int $naturalHeight,
        /** Multiplier on the object-contain base scale (1.0 = contain fit). */
        public float $scale,
        /** Pan from the frame centre, in canvas pixels. */
        public float $offsetX,
        public float $offsetY,
        /** Clockwise rotation in degrees. */
        public float $rotation,
    ) {
    }
}
