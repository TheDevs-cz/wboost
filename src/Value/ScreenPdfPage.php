<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One page of a screen PDF ("PDF k odeslání"): an sRGB JPEG drawn over the
 * whole page, the page being the trim size in PostScript points.
 */
final readonly class ScreenPdfPage
{
    public function __construct(
        public string $jpeg,
        public int $width,
        public int $height,
        public float $widthPt,
        public float $heightPt,
    ) {
    }
}
