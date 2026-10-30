<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One finished page of a print PDF: the CMYK raster that fills its bleed box
 * and the page's own geometry (trim, bleed, crop marks). Holds only the
 * encoded JPEG — a multi-page document keeps one of these per page.
 */
final readonly class PrintPage
{
    public function __construct(
        public PrintRaster $raster,
        public PrintPageGeometry $geometry,
    ) {
    }
}
