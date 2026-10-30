<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\CmykColor;
use WBoost\Web\Value\Color;
use WBoost\Web\Value\ManualColor;

/**
 * @covers \WBoost\Web\Value\CmykColor
 */
final class CmykColorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array{null|string, null|string, null|string, null|string}, null|array{float, float, float, float}}>
     */
    public static function manualEntries(): iterable
    {
        yield 'clean integers' => ['17375f', ['100', '80', '35', '25'], [100.0, 80.0, 35.0, 25.0]];
        yield 'percent signs, spaces, decimal comma' => ['17375f', [' 100 %', '80%', '35,5', '25 '], [100.0, 80.0, 35.5, 25.0]];
        yield 'blank channels next to filled ones are zeros' => ['a3c62c', ['45', null, '95', ''], [45.0, 0.0, 95.0, 0.0]];
        yield 'brand black as K100' => ['1d1d1b', ['0', '0', '0', '100'], [0.0, 0.0, 0.0, 100.0]];
        yield 'white is legitimately no ink' => ['ffffff', ['0', '0', '0', '0'], [0.0, 0.0, 0.0, 0.0]];
        yield 'all blank' => ['17375f', [null, null, null, null], null];
        yield 'placeholder letters' => ['ffcccc', ['c', 'm', 'y', 'k'], null];
        yield 'out of range' => ['17375f', ['100', '180', '35', '25'], null];
        yield 'black hex stored with no ink is a mistake' => ['000000', ['0', '0', '0', '0'], null];
        yield 'short hex works' => ['fff', ['0', '0', '0', '0'], [0.0, 0.0, 0.0, 0.0]];
    }

    /**
     * @param array{null|string, null|string, null|string, null|string} $cmyk
     * @param null|array{float, float, float, float} $expected
     */
    #[DataProvider('manualEntries')]
    public function testReadsManualCmykLeniently(string $hex, array $cmyk, null|array $expected): void
    {
        $parsed = CmykColor::fromManualColor(new ManualColor(new Color($hex), null, null, $cmyk));

        if ($expected === null) {
            self::assertNull($parsed);

            return;
        }

        self::assertNotNull($parsed);
        self::assertSame($expected, [$parsed->c, $parsed->m, $parsed->y, $parsed->k]);
    }

    public function testBlackOnly(): void
    {
        self::assertTrue((new CmykColor(0, 0, 0, 100))->isBlackOnly());
        self::assertFalse((new CmykColor(60, 40, 40, 100))->isBlackOnly());
        self::assertFalse((new CmykColor(0, 0, 0, 90))->isBlackOnly());
    }
}
