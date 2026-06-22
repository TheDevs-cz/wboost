<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

/**
 * The text placeholder's bounding box in the variant's canvas pixel coordinates
 * (top-left origin), mirroring the image-placeholder frame. A consumer can use
 * it to draw a highlight border over the rendered preview and anchor an inline
 * editing affordance at the right spot. v1 frames are axis-aligned (any object
 * rotation is ignored). Null when the textbox cannot be located on the canvas.
 */
final readonly class SocialNetworkTemplateVariantInputFrameResponse
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {
    }
}
