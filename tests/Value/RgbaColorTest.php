<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\RgbaColor;

/**
 * @covers \WBoost\Web\Value\RgbaColor
 */
final class RgbaColorTest extends TestCase
{
    public function testHexPlusOpacityPercentBecomesRgba(): void
    {
        self::assertSame('rgba(101,189,230,0.5)', RgbaColor::fromHex('65BDE6', 50)->css());
        self::assertSame('rgba(255,255,255,0.25)', RgbaColor::fromHex('#ffffff', 25)->css());
        self::assertSame('rgba(0,0,0,0)', RgbaColor::fromHex('000000', 0)->css());
        self::assertSame('rgba(0,112,184,1)', RgbaColor::fromHex('0070B8', 100)->css());
    }

    public function testShortHexIsExpanded(): void
    {
        self::assertSame('rgba(255,0,0,0.07)', RgbaColor::fromHex('F00', 7)->css());
    }

    public function testOpacityIsClampedToZeroHundred(): void
    {
        self::assertSame(1.0, RgbaColor::fromHex('ffffff', 150)->alpha);
        self::assertSame(0.0, RgbaColor::fromHex('ffffff', -5)->alpha);
    }

    public function testCssRgbaParsesBackToHexAndAlpha(): void
    {
        $color = RgbaColor::fromCss('rgba(255, 255, 255, 0.5)');

        self::assertNotNull($color);
        self::assertSame('#ffffff', $color->hex());
        self::assertSame(0.5, $color->alpha);

        $rgb = RgbaColor::fromCss('rgb(0,80,134)');
        self::assertNotNull($rgb);
        self::assertSame('#005086', $rgb->hex());
        self::assertSame(1.0, $rgb->alpha);

        self::assertSame(0.4, RgbaColor::fromCss('rgba(1,2,3,.4)')?->alpha);
        self::assertSame(0.3, RgbaColor::fromCss('rgba(1, 2, 3, 30%)')?->alpha);
    }

    public function testNonRgbaCssIsNotParsed(): void
    {
        self::assertNull(RgbaColor::fromCss('#ffffff'));
        self::assertNull(RgbaColor::fromCss('url(#grad)'));
        self::assertNull(RgbaColor::fromCss('none'));
    }
}
