<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Manual;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\SvgPortableAlpha;

/**
 * Inkscape (PNG/JPG downloads) and desktop editors paint `rgba()` as black —
 * downloads get the SVG 1.1 form: hex paint + its own opacity property.
 *
 * @covers \WBoost\Web\Services\SvgPortableAlpha
 */
final class SvgPortableAlphaTest extends TestCase
{
    public function testRgbaPaintBecomesHexPlusOpacityAttribute(): void
    {
        $svg = (new SvgPortableAlpha())->flatten(
            '<svg xmlns="http://www.w3.org/2000/svg"><path fill="rgba(255,255,255,0.5)" stroke="rgba(0, 80, 134, 0.25)"/></svg>',
        );

        self::assertStringContainsString('fill="#ffffff"', $svg);
        self::assertStringContainsString('fill-opacity="0.5"', $svg);
        self::assertStringContainsString('stroke="#005086"', $svg);
        self::assertStringContainsString('stroke-opacity="0.25"', $svg);
        self::assertStringNotContainsString('rgba', $svg);
    }

    public function testAnExistingOpacityIsMultipliedLikeTheBrowserDoes(): void
    {
        $svg = (new SvgPortableAlpha())->flatten(
            '<svg xmlns="http://www.w3.org/2000/svg"><path fill="rgba(0,0,0,0.5)" fill-opacity="0.8"/><path fill="rgba(0,0,0,0.5)" fill-opacity="50%"/></svg>',
        );

        self::assertStringContainsString('fill-opacity="0.4"', $svg);
        self::assertStringContainsString('fill-opacity="0.25"', $svg);
    }

    public function testGradientStopsUseStopOpacity(): void
    {
        $svg = (new SvgPortableAlpha())->flatten(
            '<svg xmlns="http://www.w3.org/2000/svg"><linearGradient><stop stop-color="rgba(255,0,0,0.3)"/></linearGradient></svg>',
        );

        self::assertStringContainsString('stop-color="#ff0000"', $svg);
        self::assertStringContainsString('stop-opacity="0.3"', $svg);
    }

    public function testOpaqueSvgIsLeftAlone(): void
    {
        $input = '<svg xmlns="http://www.w3.org/2000/svg"><path fill="#ffffff"/></svg>';

        self::assertStringContainsString('<path fill="#ffffff"/>', (new SvgPortableAlpha())->flatten($input));
    }
}
