<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * The CMYK JPEG that fills a print PDF's bleed box.
 */
final readonly class PrintRaster
{
    public function __construct(
        public string $jpeg,
        public int $width,
        public int $height,
    ) {
    }
}
