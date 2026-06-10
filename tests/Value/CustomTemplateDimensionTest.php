<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\CustomTemplateDimension;

/**
 * @covers \WBoost\Web\Value\CustomTemplateDimension
 * @covers \WBoost\Web\Value\DimensionUnit
 */
final class CustomTemplateDimensionTest extends TestCase
{
    public function testPixelsArePassedThroughUnchanged(): void
    {
        $dimension = new CustomTemplateDimension(DimensionUnit::Px, 1080, 1920);

        self::assertSame(1080, $dimension->width());
        self::assertSame(1920, $dimension->height());
        self::assertSame('1080 × 1920 px', $dimension->label());
    }

    public function testMillimetersRasterizeAt300Dpi(): void
    {
        // A4 portrait: 210 × 297 mm → 2480 × 3508 px at 300 DPI.
        $dimension = new CustomTemplateDimension(DimensionUnit::Mm, 210, 297);

        self::assertSame(2480, $dimension->width());
        self::assertSame(3508, $dimension->height());
        self::assertSame('210 × 297 mm', $dimension->label());
    }

    public function testCentimetersRasterizeAt300Dpi(): void
    {
        // 21 × 29.7 cm is the same physical size as A4 in mm.
        $dimension = new CustomTemplateDimension(DimensionUnit::Cm, 21, 29.7);

        self::assertSame(2480, $dimension->width());
        self::assertSame(3508, $dimension->height());
        self::assertSame('21 × 29.7 cm', $dimension->label());
    }

    public function testFractionalPixelsRoundToWholeCanvasPixels(): void
    {
        $dimension = new CustomTemplateDimension(DimensionUnit::Px, 100.4, 100.6);

        self::assertSame(100, $dimension->width());
        self::assertSame(101, $dimension->height());
    }
}
