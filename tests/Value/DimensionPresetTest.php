<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\DimensionPreset;
use WBoost\Web\Value\TemplateDimension;

/**
 * @covers \WBoost\Web\Value\DimensionPreset
 */
final class DimensionPresetTest extends TestCase
{
    public function testSquarePresetIsNoLongerOfferedForNewFormats(): void
    {
        self::assertSame(
            [DimensionPreset::InstagramPortrait, DimensionPreset::InstagramStory],
            DimensionPreset::offered(),
        );
        self::assertFalse(DimensionPreset::InstagramPost->isOffered());
        self::assertTrue(DimensionPreset::InstagramPortrait->isOffered());
        self::assertTrue(DimensionPreset::InstagramStory->isOffered());
    }

    public function testExistingSquareVariantsKeepTheirPresetDimensionAndLabel(): void
    {
        // Retired from the pickers only: stored '1:1' rows still hydrate.
        $dimension = TemplateDimension::fromPreset(DimensionPreset::from('1:1'));

        self::assertSame(DimensionPreset::InstagramPost, $dimension->preset);
        self::assertSame('1:1', $dimension->label());
        self::assertSame(1080, $dimension->width());
        self::assertSame(1080, $dimension->height());
    }
}
