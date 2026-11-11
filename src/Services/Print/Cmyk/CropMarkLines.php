<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print\Cmyk;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Crop marks for the pages of a CMYK file: straight lines in pt, measured
 * from the page's TOP-left corner (x right, y down — where Chromium anchors
 * the sheet). {@see PdfCmykConverter} strokes them in the registration
 * colour (`/Separation /All`): on all four plates, as print shops expect of
 * marks, and never part of the artwork's colours.
 */
#[Exclude]
readonly final class CropMarkLines
{
    /**
     * @param list<array{float, float, float, float}> $lines x1, y1, x2, y2
     */
    public function __construct(
        public array $lines,
        public float $weight,
    ) {
    }

    /**
     * Eight marks around a trim box that lies `$trimInset` inside a
     * `$width` × `$height` sheet, each on a trim line, starting `$offset`
     * from the trim corner and `$length` long (all pt).
     */
    public static function around(float $width, float $height, float $trimInset, float $offset, float $length, float $weight): self
    {
        $left = $trimInset;
        $top = $trimInset;
        $right = $width - $trimInset;
        $bottom = $height - $trimInset;
        $a = $offset;
        $b = $offset + $length;

        return new self([
            [$left - $b, $top, $left - $a, $top], [$left, $top - $b, $left, $top - $a],
            [$right + $a, $top, $right + $b, $top], [$right, $top - $b, $right, $top - $a],
            [$left - $b, $bottom, $left - $a, $bottom], [$left, $bottom + $a, $left, $bottom + $b],
            [$right + $a, $bottom, $right + $b, $bottom], [$right, $bottom + $a, $right, $bottom + $b],
        ], $weight);
    }
}
