<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

/**
 * The designer's fixed frame for an image placeholder, in the variant's canvas
 * pixel coordinates (top-left origin). A consumer can use it to reason about
 * `offsetX`/`offsetY` when positioning a picture, or ignore it and send only an
 * `imageId` for the default centered object-contain fit.
 */
final readonly class SocialNetworkTemplateVariantImageInputFrameResponse
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {
    }
}
