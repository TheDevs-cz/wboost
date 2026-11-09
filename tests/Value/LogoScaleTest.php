<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\LogoScale;

/**
 * "Šířka loga v manuálu" is a percentage of the logo's DEFAULT size in that
 * card (100 % = výchozí), not of the frame. The rendering itself happens in
 * CSS (`--logo-scale` in app.css); `renderedWidth()` is the contract the
 * stylesheet implements and the legacy conversion is checked against.
 *
 * @covers \WBoost\Web\Value\LogoScale
 */
final class LogoScaleTest extends TestCase
{
    /**
     * @return iterable<string, array{null|int, float}>
     */
    public static function scales(): iterable
    {
        // default 120 px wide logo in a 320 px frame
        yield 'no value = default' => [null, 120.0];
        yield '50 % = half' => [50, 60.0];
        yield '100 % = default' => [100, 120.0];
        yield '150 % = 1.5x' => [150, 180.0];
        yield '200 % = double' => [200, 240.0];
    }

    #[DataProvider('scales')]
    public function testRendersRelativeToTheDefaultWidth(null|int $percent, float $expected): void
    {
        self::assertEqualsWithDelta($expected, LogoScale::renderedWidth($percent, 120.0, 320.0), 0.001);
    }

    public function testGrowingIsClampedToTheFrame(): void
    {
        // the default already fills most of the frame — 150 % cannot leave it
        self::assertEqualsWithDelta(300.0, LogoScale::renderedWidth(150, 250.0, 300.0), 0.001);
        self::assertEqualsWithDelta(300.0, LogoScale::renderedWidth(200, 300.0, 300.0), 0.001);
        // shrinking is never clamped
        self::assertEqualsWithDelta(150.0, LogoScale::renderedWidth(50, 300.0, 300.0), 0.001);
    }

    /**
     * The protective-zone card ("Ochranná zóna"): the logo's white area hugs
     * the logo and the zone is 1/3 of the logo HEIGHT on every side. Default
     * = the card's height cap, like every other card, but at most 2/3 of the
     * frame's room; growing stops where logo + both side zones fill the frame.
     *
     * @return iterable<string, array{null|int, float, float, float, float, float}>
     */
    public static function protectedZones(): iterable
    {
        // frydlant horizontal: 1017.8 x 751.4, cap 120 px, frame 808 px
        $wide = 1017.8 / 751.4;
        yield 'horizontal, default = height cap' => [null, $wide, 120.0, 808.0, 120.0 * $wide, 120.0];
        yield 'horizontal, 50 %' => [50, $wide, 120.0, 808.0, 60.0 * $wide, 60.0];
        yield 'horizontal, 150 %' => [150, $wide, 120.0, 808.0, 180.0 * $wide, 180.0];
        yield 'horizontal, 200 %' => [200, $wide, 120.0, 808.0, 240.0 * $wide, 240.0];
        // frydlant symbol: 121.4 x 751.4, cap 200 px, half-width frame 319 px
        $tall = 121.4 / 751.4;
        yield 'tall, default' => [null, $tall, 200.0, 319.0, 200.0 * $tall, 200.0];
        yield 'tall, 50 %' => [50, $tall, 200.0, 319.0, 100.0 * $tall, 100.0];
        yield 'tall, 150 %' => [150, $tall, 200.0, 319.0, 300.0 * $tall, 300.0];
        // 400 px tall would need 2 x 133 px of side zone: clamped to the frame
        $fit = 319.0 * 3 * $tall / (3 * $tall + 2);
        yield 'tall, 200 % clamped to the frame' => [200, $tall, 200.0, 319.0, $fit, $fit / $tall];
        // celadna vertical: 340.5 x 225.1 in a half-width card — capped at
        // 200 px tall it would fill the frame at 100 %, leaving nothing to grow
        $landscape = 340.5 / 225.1;
        $fitLandscape = 319.0 * 3 * $landscape / (3 * $landscape + 2);
        yield 'landscape in a half card, default = 2/3 of the room' => [null, $landscape, 200.0, 319.0, $fitLandscape * 2 / 3, $fitLandscape * 2 / 3 / $landscape];
        yield 'landscape in a half card, 150 % fills it' => [150, $landscape, 200.0, 319.0, $fitLandscape, $fitLandscape / $landscape];
        yield 'landscape in a half card, 200 % clamped' => [200, $landscape, 200.0, 319.0, $fitLandscape, $fitLandscape / $landscape];
        yield 'landscape in a half card, 50 %' => [50, $landscape, 200.0, 319.0, $fitLandscape / 3, $fitLandscape / 3 / $landscape];
    }

    #[DataProvider('protectedZones')]
    public function testProtectedZoneHugsTheLogoAndIsAThirdOfItsHeight(null|int $percent, float $ratio, float $cap, float $frame, float $width, float $height): void
    {
        $zone = LogoScale::protectedZone($percent, $ratio, $cap, $frame);

        self::assertEqualsWithDelta($width, $zone['width'], 0.01);
        self::assertEqualsWithDelta($height, $zone['height'], 0.01);
        self::assertEqualsWithDelta($height / 3, $zone['zone'], 0.01);
        self::assertEqualsWithDelta($zone['width'] / $ratio, $zone['height'], 0.01, 'the white area has the logo\'s own aspect ratio');
        self::assertLessThanOrEqual($frame + 0.01, $zone['width'] + 2 * $zone['zone'], 'logo + zones never leave the frame');
    }

    /**
     * @return iterable<string, array{null|int, null|int}>
     */
    public static function normalized(): iterable
    {
        yield 'empty stays empty' => [null, null];
        yield 'zero clears (legacy "0 = výchozí")' => [0, null];
        yield 'negative clears' => [-5, null];
        yield 'below the minimum is raised to it' => [3, LogoScale::MIN];
        yield 'minimum' => [10, 10];
        yield 'in range' => [150, 150];
        yield 'maximum' => [200, 200];
        yield 'above the maximum is capped' => [250, LogoScale::MAX];
    }

    #[DataProvider('normalized')]
    public function testNormalize(null|int $input, null|int $expected): void
    {
        self::assertSame($expected, LogoScale::normalize($input));
    }

    public function testCssFactor(): void
    {
        self::assertNull(LogoScale::cssFactor(null));
        self::assertNull(LogoScale::cssFactor(100), '100 % renders exactly like no value — no inline style');
        self::assertSame('0.5', LogoScale::cssFactor(50));
        self::assertSame('1.5', LogoScale::cssFactor(150));
        self::assertSame('2', LogoScale::cssFactor(200));
        self::assertSame('0.83', LogoScale::cssFactor(83));
    }

    /**
     * Prod cards measured in Chromium before the switch (2026-11-08): legacy
     * value (% of the frame), the logo's default width and the frame, both px.
     *
     * @return iterable<string, array{int, float, float, int}>
     */
    public static function legacyCards(): iterable
    {
        yield 'default height-capped (sagena symbol)' => [55, 275.0, 319.0, 64];
        yield 'default height-capped (celadna vertical)' => [75, 303.0, 319.0, 79];
        yield 'default fills the frame (sagena symbol page)' => [55, 269.0, 270.0, 55];
        yield 'legacy wider than the default (spolecne min. rozměry)' => [85, 230.0, 300.0, 111];
    }

    #[DataProvider('legacyCards')]
    public function testLegacyFramePercentConvertsToTheSameRenderedWidth(int $legacy, float $default, float $frame, int $expected): void
    {
        $converted = LogoScale::fromLegacyFramePercent($legacy, $default, $frame);

        self::assertSame($expected, $converted);

        // legacy rendering: N % of the frame, height cap lifted
        $legacyWidth = $frame * $legacy / 100;
        self::assertEqualsWithDelta(
            $legacyWidth,
            LogoScale::renderedWidth($converted, $default, $frame),
            $default / 100, // integer percent ⇒ at most ±0.5 % of the default off
        );
    }
}
