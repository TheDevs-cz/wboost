<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Palette;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Palette\PaletteColorMerge;

/**
 * The "fill the gap" rule of "Sloučit" (F7 P4, plan §7).
 *
 * @covers \WBoost\Web\Services\Palette\PaletteColorMerge
 */
final class PaletteColorMergeTest extends TestCase
{
    public function testFillsOnlyTheSurvivorsEmptyFieldsFromTheFirstLoserThatHasThem(): void
    {
        $merged = PaletteColorMerge::mergedFields(
            ['cmyk' => [' ', null, '', null], 'pantone' => '2299 C', 'ral' => null, 'name' => ' '],
            [
                ['cmyk' => null, 'pantone' => '375 C', 'ral' => null, 'name' => 'Zelená'],
                ['cmyk' => ['45', '0', '90', '0'], 'pantone' => null, 'ral' => 'RAL 6018', 'name' => 'Jiná'],
            ],
        );

        self::assertSame([
            'cmyk' => ['45', '0', '90', '0'],
            'pantone' => '2299 C',
            'ral' => 'RAL 6018',
            'name' => 'Zelená',
        ], $merged);
    }

    public function testAFilledSurvivorKeepsEverything(): void
    {
        $survivor = ['cmyk' => ['45', null, '95', null], 'pantone' => '2299 C', 'ral' => 'RAL 6018', 'name' => 'Zelená'];

        self::assertSame($survivor, PaletteColorMerge::mergedFields($survivor, [['cmyk' => ['45', '0', '90', '0'], 'pantone' => 'X', 'ral' => 'Y', 'name' => 'Z']]));
    }

    public function testEmptinessAndFormatting(): void
    {
        self::assertTrue(PaletteColorMerge::isEmpty(null));
        self::assertTrue(PaletteColorMerge::isEmpty('  '));
        self::assertTrue(PaletteColorMerge::isEmpty([null, ' ', '', null]));
        self::assertFalse(PaletteColorMerge::isEmpty(['0', null, null, null]));
        self::assertFalse(PaletteColorMerge::isEmpty('0'));

        self::assertSame('45/–/95/0', PaletteColorMerge::format(['45', null, '95', '0']));
        self::assertSame('–', PaletteColorMerge::format(null));
        self::assertSame('2299 C', PaletteColorMerge::format(' 2299 C '));
    }
}
