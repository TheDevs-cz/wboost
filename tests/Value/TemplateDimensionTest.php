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

    public function testPrintSettingsDefaultToNone(): void
    {
        $dimension = new TemplateDimension(DimensionUnit::Mm, 210, 297);

        self::assertSame(0.0, $dimension->bleedMm);
        self::assertFalse($dimension->cropMarks);
        self::assertFalse($dimension->hasBleed());
        self::assertSame(0, $dimension->bleedPixels());
    }

    public function testBleedConvertsAtPrintResolutionForPrintFormatsOnly(): void
    {
        // 3 mm at 300 DPI = 35.43 px → 35.
        $mm = (new TemplateDimension(DimensionUnit::Mm, 210, 297))->withPrintSettings(3, true);
        $cm = (new TemplateDimension(DimensionUnit::Cm, 21, 29.7))->withPrintSettings(3, true);
        $px = TemplateDimension::fromPreset(DimensionPreset::InstagramPost)->withPrintSettings(3, true);

        self::assertTrue($mm->isPrintFormat());
        self::assertSame(35, $mm->bleedPixels());
        self::assertTrue($mm->cropMarks);
        self::assertSame(35, $cm->bleedPixels());
        // A px canvas is a screen format: no print setup at all.
        self::assertFalse($px->isPrintFormat());
        self::assertSame(0, $px->bleedPixels());
        self::assertFalse($px->cropMarks);
        self::assertSame(DimensionPreset::InstagramPost, $px->preset);
        // Canvas size is the TRIM size — the bleed never enters it.
        self::assertSame(2480, $mm->width());
        self::assertSame(3508, $mm->height());
    }

    public function testNegativeBleedClampsToZero(): void
    {
        $dimension = (new TemplateDimension(DimensionUnit::Mm, 210, 297))->withPrintSettings(-2, false);

        self::assertSame(0.0, $dimension->bleedMm);
    }
}
