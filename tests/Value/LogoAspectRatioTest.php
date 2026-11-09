<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\LogoAspectRatio;

/**
 * The protective-zone card ("Ochranná zóna") needs the logo's aspect ratio in
 * CSS: the zone is 1/3 of the logo HEIGHT on every side and the logo's white
 * area must hug the logo edge to edge, which a stylesheet cannot derive from
 * a viewBox-only SVG on its own.
 *
 * @covers \WBoost\Web\Value\LogoAspectRatio
 */
final class LogoAspectRatioTest extends TestCase
{
    /**
     * @return iterable<string, array{string, null|float}>
     */
    public static function svgs(): iterable
    {
        // Real prod root tags (2026-11-09 survey of every protection-zone logo).
        yield 'viewBox only (frydlant horizontal)' => ['<svg id="Vrstva_1" xmlns="http://www.w3.org/2000/svg" version="1.1" viewBox="0 0 1017.8 751.4">', 1017.8 / 751.4];
        yield 'viewBox only, tall (frydlant symbol)' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 121.4 751.4">', 121.4 / 751.4];
        yield 'viewBox wins over mm width/height (ostravice)' => ['<svg width="172.893mm" height="55.0685mm" viewBox="0 0 17272.76 5501.58">', 17272.76 / 5501.58];
        yield 'comma-separated viewBox, offset origin' => ['<svg viewBox="-10,-10,200,100">', 2.0];
        yield 'single-quoted, multi-line root tag' => ["<svg\n  xmlns='http://www.w3.org/2000/svg'\n  viewBox='0 0 50 100'\n>", 0.5];
        yield 'no viewBox: width / height in the same unit' => ['<svg width="300px" height="100px">', 3.0];
        yield 'no viewBox: unitless width / height' => ['<svg width="120" height="60">', 2.0];
        yield 'no viewBox: percentage size is unknown' => ['<svg width="100%" height="100%">', null];
        yield 'no viewBox: mixed units are unknown' => ['<svg width="10mm" height="100px">', null];
        yield 'zero-height viewBox is unknown' => ['<svg viewBox="0 0 100 0">', null];
        yield 'garbage viewBox is unknown' => ['<svg viewBox="auto">', null];
        yield 'not an svg' => ['<html><body>nope</body></html>', null];
        yield 'nested svg does not count, the root does' => ['<?xml version="1.0"?><!-- <svg viewBox="0 0 1 1"> --><svg viewBox="0 0 400 100"><svg viewBox="0 0 1 9"/></svg>', 4.0];
    }

    #[DataProvider('svgs')]
    public function testReadsTheRootSvgAspectRatio(string $svg, null|float $expected): void
    {
        $ratio = LogoAspectRatio::fromSvg($svg);

        if ($expected === null) {
            self::assertNull($ratio);

            return;
        }

        self::assertNotNull($ratio);
        self::assertEqualsWithDelta($expected, $ratio, 0.0001);
    }

    public function testReadsABase64DataUri(): void
    {
        $uri = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1017.8 751.4"><path d="M0 0"/></svg>');

        self::assertSame('1.3545', LogoAspectRatio::cssValueOfDataUri($uri));
    }

    public function testReadsAPercentEncodedDataUri(): void
    {
        $uri = 'data:image/svg+xml,' . rawurlencode('<svg viewBox="0 0 30 10"></svg>');

        self::assertSame('3', LogoAspectRatio::cssValueOfDataUri($uri));
    }

    public function testUnknownRatioRendersNoCssValue(): void
    {
        self::assertNull(LogoAspectRatio::cssValueOfDataUri('data:image/svg+xml;base64,' . base64_encode('<svg/>')));
        self::assertNull(LogoAspectRatio::cssValueOfDataUri('https://example.com/logo.svg'));
        self::assertNull(LogoAspectRatio::cssValueOfDataUri('data:image/svg+xml;base64,!!!not-base64!!!'));
    }
}
