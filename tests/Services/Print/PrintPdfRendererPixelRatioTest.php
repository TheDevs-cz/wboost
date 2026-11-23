<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Print;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Print\PrintPdfRenderer;
use WBoost\Web\Value\DimensionUnit;

/**
 * The print raster's resolution: 1200 ppi for small formats (business
 * cards), 600 for mid, the historical 300 for A4 and up — bounded by the
 * per-pass pixel budget.
 */
final class PrintPdfRendererPixelRatioTest extends TestCase
{
    /**
     * @return iterable<string, array{float, float, float, int}>
     */
    public static function formats(): iterable
    {
        yield 'business card 90 x 50, 5 mm bleed → 1200 ppi' => [90, 50, 5, 4];
        yield 'A6 105 x 148, 3 mm bleed → 600 ppi' => [105, 148, 3, 2];
        yield 'A5 148 x 210, 3 mm bleed → 600 ppi' => [148, 210, 3, 2];
        yield 'A4 210 x 297, 3 mm bleed → 300 ppi' => [210, 297, 3, 1];
        yield 'A2 → 300 ppi' => [420, 594, 3, 1];
    }

    #[DataProvider('formats')]
    public function testRatioFollowsThePixelBudget(float $widthMm, float $heightMm, float $bleedMm, int $expected): void
    {
        $width = DimensionUnit::Mm->toPixels($widthMm);
        $height = DimensionUnit::Mm->toPixels($heightMm);
        $bleed = DimensionUnit::Mm->toPixels($bleedMm);

        $ratio = PrintPdfRenderer::pixelRatioFor($width, $height, $bleed);

        self::assertSame($expected, $ratio);
        if ($ratio > 1) {
            self::assertLessThanOrEqual(PrintPdfRenderer::MAX_RASTER_PIXELS, ($width + 2 * $bleed) * ($height + 2 * $bleed) * $ratio * $ratio);
        }
    }
}
