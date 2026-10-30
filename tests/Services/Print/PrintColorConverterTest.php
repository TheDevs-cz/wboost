<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Print;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Print\PrintColorConverter;
use WBoost\Web\Value\CmykColor;

/**
 * @covers \WBoost\Web\Services\Print\PrintColorConverter
 */
final class PrintColorConverterTest extends TestCase
{
    public function testBrandNumbersIccConversionAndBlackOverprint(): void
    {
        $projectDir = dirname(__DIR__, 3);
        $converter = new PrintColorConverter(
            $projectDir . '/resources/icc/sRGB-v4.icc',
            $projectDir . '/resources/icc/CoatedFOGRA39.icc',
        );

        // Left half brand blue, right half red, top-right corner transparent.
        $composite = $this->png(40, 20, static function (\ImagickDraw $draw): void {
            $draw->setFillColor('#17375f');
            $draw->rectangle(0, 0, 19, 19);
            $draw->setFillColor('#ff0000');
            $draw->rectangle(20, 5, 39, 19);
        });
        // Black-ink mask: a solid block in the blue, a half-opaque one in the red.
        $mask = $this->png(40, 20, static function (\ImagickDraw $draw): void {
            $draw->setFillColor('rgba(0, 0, 0, 1)');
            $draw->rectangle(2, 2, 7, 7);
            $draw->setFillColor('rgba(0, 0, 0, 0.5)');
            $draw->rectangle(30, 10, 35, 15);
        });

        $raster = $converter->toCmykJpeg($composite, $mask, ['#17375f' => new CmykColor(100, 80, 35, 25)]);

        self::assertSame(40, $raster->width);
        self::assertSame(20, $raster->height);

        $image = new \Imagick();
        $image->readImageBlob($raster->jpeg);
        self::assertSame(\Imagick::COLORSPACE_CMYK, $image->getImageColorspace());

        $ink = static function (int $x, int $y) use ($image): array {
            $pixel = $image->getImagePixelColor($x, $y);

            return array_map(
                static fn (int $channel): int => (int) round($pixel->getColorValue($channel) * 100),
                [\Imagick::COLOR_CYAN, \Imagick::COLOR_MAGENTA, \Imagick::COLOR_YELLOW, \Imagick::COLOR_BLACK],
            );
        };

        // JPEG at q95 wobbles a percent or two; the claims hold well within that.
        self::assertEqualsWithDelta([100, 80, 35, 25], $ink(14, 14), 2, 'brand colour keeps the manual numbers');
        self::assertEqualsWithDelta([100, 80, 35, 100], $ink(4, 4), 2, 'black overprints: K100 over the untouched blue');
        self::assertEqualsWithDelta([0, 0, 0, 0], $ink(30, 1), 1, 'transparent design prints as paper');

        [$c, $m, $y, $k] = $ink(25, 17);
        self::assertLessThan(5, $c, 'red goes through ICC: no cyan');
        self::assertGreaterThan(90, $m);
        self::assertGreaterThan(90, $y);

        [, , , $halfK] = $ink(32, 12);
        self::assertEqualsWithDelta(50, $halfK, 4, 'partial coverage raises K proportionally');
    }

    /**
     * @param callable(\ImagickDraw): void $paint
     */
    private function png(int $width, int $height, callable $paint): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('transparent'));
        $draw = new \ImagickDraw();
        $paint($draw);
        $image->drawImage($draw);
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }
}
