<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Print\Cmyk;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Print\Cmyk\CmykColorRules;
use WBoost\Web\Services\Print\Cmyk\IccColorTransform;
use WBoost\Web\Value\CmykColor;

/**
 * @covers \WBoost\Web\Services\Print\Cmyk\CmykColorRules
 * @covers \WBoost\Web\Services\Print\Cmyk\IccColorTransform
 */
final class CmykColorRulesTest extends TestCase
{
    public static function icc(): IccColorTransform
    {
        $root = dirname(__DIR__, 4);

        return new IccColorTransform($root . '/resources/icc/sRGB-v4.icc', $root . '/resources/icc/CoatedFOGRA39.icc');
    }

    public function testNeutralColoursPrintWithBlackInkOnly(): void
    {
        $rules = new CmykColorRules(self::icc());

        self::assertSame([0.0, 0.0, 0.0, 1.0], $rules->cmyk(0, 0, 0), 'black text is K 100, never a rich black');
        self::assertSame([0.0, 0.0, 0.0, 0.0], $rules->cmyk(1, 1, 1), 'white is no ink');
        self::assertSame([0.0, 0.0, 0.0, 0.498], $rules->cmyk8(128, 128, 128));
        // Chromium writes n / 255 with four decimals: .2 is 51 / 255
        self::assertSame([0.0, 0.0, 0.0, 0.8], $rules->cmyk(0.2, 0.2, 0.2));
        self::assertSame(['neutral' => 4, 'brand' => 0, 'text' => 0, 'icc' => 0], $rules->counts());
    }

    public function testABrandColourKeepsThePalettesNumbers(): void
    {
        $rules = new CmykColorRules(self::icc(), ['#5b47a8' => new CmykColor(75, 80, 0, 5), '#E8590C' => new CmykColor(0, 75, 100, 0), 'nonsense' => new CmykColor(1, 2, 3, 4)]);

        self::assertSame([0.75, 0.8, 0.0, 0.05], $rules->cmyk(0.3569, 0.2784, 0.6588), 'hex match after the 8-bit rounding of Chromium\'s decimals');
        self::assertSame([0.0, 0.75, 1.0, 0.0], $rules->cmyk8(0xE8, 0x59, 0x0C), 'the palette key is matched case-insensitively');
        self::assertSame(['neutral' => 0, 'brand' => 2, 'text' => 0, 'icc' => 0], $rules->counts());
    }

    public function testANeutralBrandColourStaysBlackOnly(): void
    {
        $rules = new CmykColorRules(self::icc(), [
            '#c6c6c6' => new CmykColor(0, 0, 0, 30),
            // a four-colour grey in the palette must not put C, M, Y under grey text
            '#808080' => new CmykColor(40, 30, 30, 10),
            '#000000' => new CmykColor(60, 40, 40, 100),
        ]);

        self::assertSame([0.0, 0.0, 0.0, 0.3], $rules->cmyk8(0xC6, 0xC6, 0xC6), 'a K-only palette entry gives its own K');
        self::assertSame([0.0, 0.0, 0.0, 0.498], $rules->cmyk8(0x80, 0x80, 0x80));
        self::assertSame([0.0, 0.0, 0.0, 1.0], $rules->cmyk8(0, 0, 0), 'a rich black in the palette is still K 100 for text');
    }

    public function testEverythingElseGoesThroughTheProfile(): void
    {
        $rules = new CmykColorRules(self::icc());
        [$c, $m, $y, $k] = $rules->cmyk8(255, 0, 0);

        // sRGB red in Coated FOGRA39, relative colorimetric + black point compensation
        self::assertEqualsWithDelta(0.0, $c, 0.02);
        self::assertEqualsWithDelta(0.99, $m, 0.03);
        self::assertEqualsWithDelta(1.0, $y, 0.03);
        self::assertEqualsWithDelta(0.0, $k, 0.02);

        [$c, $m, $y, $k] = $rules->cmyk8(0, 0, 1);
        self::assertGreaterThan(2.4, $c + $m + $y + $k, 'a near-black that is not neutral is a four-colour dark, as the profile says');

        self::assertSame($rules->cmyk8(255, 0, 0), $rules->cmyk(1.0, 0.0, 0.0));
        self::assertSame(['neutral' => 0, 'brand' => 0, 'text' => 0, 'icc' => 2], $rules->counts(), 'each colour is converted once');
    }

    public function testNearlyNeutralTextPrintsInBlackOnlyButFillsKeepTheirTint(): void
    {
        $rules = new CmykColorRules(self::icc(), ['#6e6977' => new CmykColor(10, 12, 0, 55)]);

        // the calendar's default text colour #2B2830: a tinted near-black
        self::assertSame([0.0, 0.0, 0.0, 0.836], $rules->textCmyk(0x2B / 255, 0x28 / 255, 0x30 / 255), 'K = 1 − luma');
        [$c, $m, $y, $k] = $rules->cmyk8(0x2B, 0x28, 0x30);
        self::assertGreaterThan(1.5, $c + $m + $y, 'the same colour as a FILL goes through the profile and keeps its tint');
        self::assertGreaterThan(0.3, $k);

        // exactly neutral, a brand colour and a real colour are what they are anywhere
        self::assertSame([0.0, 0.0, 0.0, 1.0], $rules->textCmyk(0, 0, 0));
        self::assertSame([0.1, 0.12, 0.0, 0.55], $rules->textCmyk(0x6E / 255, 0x69 / 255, 0x77 / 255), 'the palette decides for its own colours');
        self::assertSame($rules->cmyk8(0x2F, 0x78, 0xB7), $rules->textCmyk(0x2F / 255, 0x78 / 255, 0xB7 / 255), 'blue text is blue');

        // the limit: R, G, B within 16 of each other
        self::assertSame(0.0, $rules->textCmyk(100 / 255, 108 / 255, 116 / 255)[0]);
        self::assertGreaterThan(0.0, $rules->textCmyk(100 / 255, 108 / 255, 117 / 255)[0]);
        self::assertSame(['neutral' => 1, 'brand' => 1, 'text' => 2, 'icc' => 3], $rules->counts());
    }

    public function testLuminosityIsWhatPdfComputesForRgb(): void
    {
        self::assertSame(1.0, CmykColorRules::luminosity(1, 1, 1));
        self::assertSame(0.4, CmykColorRules::luminosity(0.4, 0.4, 0.4));
        self::assertSame(0.3, CmykColorRules::luminosity(1, 0, 0));
    }

    public function testSwatchesAnswerEveryColourOnce(): void
    {
        $table = self::icc()->swatches([0x112233, 0xFF0000, 0x112233]);

        self::assertSame([0x112233, 0xFF0000], array_keys($table));
        self::assertSame([], self::icc()->swatches([]));
    }
}
