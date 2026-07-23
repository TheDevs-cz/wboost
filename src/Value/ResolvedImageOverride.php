<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A single image placeholder's resolved fill: the chosen picture already inlined
 * as a base64 data URI (so the headless Gotenberg render needs no Minio access),
 * its natural pixel size, and the validated frame-relative transform. The
 * renderer combines this with the placeholder's {@see PlaceholderFrame} to
 * compute the absolute Fabric placement.
 *
 * The pan comes in two interchangeable forms. `offsetX`/`offsetY` are ABSOLUTE
 * canvas pixels — exact for one variant, but meaningless in another dimension
 * where the same placeholder has a different frame. `offsetXRatio`/
 * `offsetYRatio` are the same pan expressed as a FRACTION of the frame's width /
 * height, which travels between dimensions unchanged: the template group's fill
 * page fans one placement out over every member variant with it, and API
 * consumers reusing a placement across variants can do the same. When a ratio is
 * present it wins for that axis ({@see \WBoost\Web\Services\SocialNetwork\ImagePlacement}
 * resolves it against the frame); `scale` and `rotation` are already
 * dimension-independent, so they need no second form.
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
        /** Pan from the frame centre as a fraction of the frame size; wins over the px form. */
        public null|float $offsetXRatio = null,
        public null|float $offsetYRatio = null,
    ) {
    }
}
