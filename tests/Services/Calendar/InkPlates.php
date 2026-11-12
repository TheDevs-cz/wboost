<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Calendar;

/**
 * The four plates of one rendered PDF page (Ghostscript `tiff32nc`: a byte
 * of cyan, magenta, yellow and black per pixel, 255 = full ink), addressed
 * in mm from the page's TRIM corner — what {@see CalendarCmykExportGotenbergTest}
 * reads its inks from.
 */
final readonly class InkPlates
{
    /**
     * @param string $pixels C, M, Y, K bytes, row by row
     * @param int $px pixels per mm
     * @param float $inset distance of the trim corner from the sheet corner, mm
     */
    public function __construct(
        private string $pixels,
        private int $width,
        private int $height,
        private int $px,
        private float $inset,
    ) {
    }

    /**
     * @return array{int, int, int, int} percent of C, M, Y, K
     */
    public function at(float $xMm, float $yMm): array
    {
        return $this->pixel((int) round(($this->inset + $xMm) * $this->px), (int) round(($this->inset + $yMm) * $this->px));
    }

    /**
     * @return array{int, int, int, int} the pixel with the most ink in a rectangle
     */
    public function darkest(float $x0, float $y0, float $x1, float $y1): array
    {
        $darkest = [0, 0, 0, 0];

        foreach ($this->area($x0, $y0, $x1, $y1) as [$x, $y]) {
            $pixel = $this->pixel($x, $y);

            if (array_sum($pixel) > array_sum($darkest)) {
                $darkest = $pixel;
            }
        }

        return $darkest;
    }

    /**
     * @return array{int, int, int} how many pixels of the rectangle have K ≥ 50 %, how many of those carry any C, M or Y, and how many are K 100
     */
    public function darkPixels(float $x0, float $y0, float $x1, float $y1): array
    {
        $dark = 0;
        $rich = 0;
        $solid = 0;

        foreach ($this->area($x0, $y0, $x1, $y1) as [$x, $y]) {
            [$c, $m, $yellow, $k] = $this->pixel($x, $y);

            if ($k >= 50) {
                ++$dark;
                $rich += $c + $m + $yellow > 0 ? 1 : 0;
                $solid += $k === 100 ? 1 : 0;
            }
        }

        return [$dark, $rich, $solid];
    }

    /**
     * @return iterable<array{int, int}>
     */
    private function area(float $x0, float $y0, float $x1, float $y1): iterable
    {
        for ($y = (int) round(($this->inset + $y0) * $this->px), $bottom = (int) round(($this->inset + $y1) * $this->px); $y <= $bottom; ++$y) {
            for ($x = (int) round(($this->inset + $x0) * $this->px), $right = (int) round(($this->inset + $x1) * $this->px); $x <= $right; ++$x) {
                yield [$x, $y];
            }
        }
    }

    /**
     * @return array{int, int, int, int}
     */
    private function pixel(int $x, int $y): array
    {
        $at = 4 * (max(0, min($this->height - 1, $y)) * $this->width + max(0, min($this->width - 1, $x)));

        return [
            (int) round(ord($this->pixels[$at]) / 2.55),
            (int) round(ord($this->pixels[$at + 1]) / 2.55),
            (int) round(ord($this->pixels[$at + 2]) / 2.55),
            (int) round(ord($this->pixels[$at + 3]) / 2.55),
        ];
    }
}
