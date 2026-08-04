<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\DimensionPreset;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\TemplateDimension;

/**
 * @covers \WBoost\Web\Value\TemplateDimension
 * @covers \WBoost\Web\Value\DimensionUnit
 */
final class TemplateDimensionTest extends TestCase
{
    public function testPixelsArePassedThroughUnchanged(): void
    {
        $dimension = new TemplateDimension(DimensionUnit::Px, 1080, 1920);

        self::assertSame(1080, $dimension->width());
        self::assertSame(1920, $dimension->height());
        self::assertSame('1080 × 1920 px', $dimension->label());
    }

    public function testMillimetersRasterizeAt300Dpi(): void
    {
        // A4 portrait: 210 × 297 mm → 2480 × 3508 px at 300 DPI.
        $dimension = new TemplateDimension(DimensionUnit::Mm, 210, 297);

        self::assertSame(2480, $dimension->width());
        self::assertSame(3508, $dimension->height());
        self::assertSame('210 × 297 mm', $dimension->label());
    }

    public function testCentimetersRasterizeAt300Dpi(): void
    {
        // 21 × 29.7 cm is the same physical size as A4 in mm.
        $dimension = new TemplateDimension(DimensionUnit::Cm, 21, 29.7);

        self::assertSame(2480, $dimension->width());
        self::assertSame(3508, $dimension->height());
        self::assertSame('21 × 29.7 cm', $dimension->label());
    }

    public function testFractionalPixelsRoundToWholeCanvasPixels(): void
    {
        $dimension = new TemplateDimension(DimensionUnit::Px, 100.4, 100.6);

        self::assertSame(100, $dimension->width());
        self::assertSame(101, $dimension->height());
    }

    public function testPresetDimensionCarriesFixedPixelSizeAndRatioLabel(): void
    {
        $dimension = TemplateDimension::fromPreset(DimensionPreset::InstagramStory);

        self::assertSame(1080, $dimension->width());
        self::assertSame(1920, $dimension->height());
        self::assertSame(DimensionUnit::Px, $dimension->unit);
        self::assertSame(DimensionPreset::InstagramStory, $dimension->preset);
        // Compact ratio label — what the social module always displayed.
        self::assertSame('9:16', $dimension->label());
    }

    public function testFreeFormDimensionHasNoPreset(): void
    {
        $dimension = new TemplateDimension(DimensionUnit::Px, 1080, 1920);

        self::assertNull($dimension->preset);
        self::assertSame('1080 × 1920 px', $dimension->label());
    }
}
