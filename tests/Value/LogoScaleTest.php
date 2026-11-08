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
