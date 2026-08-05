<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Image;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Image\NormalizeImageFormat;

final class NormalizeImageFormatTest extends TestCase
{
    public function testPassesPngThroughUntouched(): void
    {
        $png = self::render('png', 40, 25);

        $result = (new NormalizeImageFormat())->normalize($png);

        self::assertNotNull($result);
        self::assertSame($png, $result['contents']);
        self::assertSame('png', $result['extension']);
        self::assertSame('image/png', $result['mimeType']);
        self::assertSame(40, $result['width']);
        self::assertSame(25, $result['height']);
    }

    public function testPassesJpegThroughUntouched(): void
    {
        $jpeg = self::render('jpeg', 32, 16);

        $result = (new NormalizeImageFormat())->normalize($jpeg);

        self::assertNotNull($result);
        self::assertSame($jpeg, $result['contents']);
        self::assertSame('jpg', $result['extension']);
        self::assertSame('image/jpeg', $result['mimeType']);
    }

    /**
     * The reported bug: an iPhone photo uploads as HEIC, which neither
     * getimagesizefromstring() nor Chromium can read, so the export failed with
     * "could not be read or is not a supported raster image".
     */
    public function testTranscodesHeicToJpeg(): void
    {
        if (!in_array('HEIC', \Imagick::queryFormats(), true)) {
            self::markTestSkipped('ImageMagick was built without HEIC support.');
        }

        $heic = self::render('heic', 60, 30);

        $result = (new NormalizeImageFormat())->normalize($heic);

        self::assertNotNull($result);
        self::assertSame('jpg', $result['extension']);
        self::assertSame('image/jpeg', $result['mimeType']);
        self::assertSame(60, $result['width']);
        self::assertSame(30, $result['height']);

        // The point of the conversion: the result is readable by the very call
        // that used to fail.
        $size = @getimagesizefromstring($result['contents']);
        self::assertNotFalse($size);
        self::assertSame([60, 30], [$size[0], $size[1]]);
    }

    public function testKeepsTransparencyByConvertingToPng(): void
    {
        $image = new \Imagick();
        $image->newImage(20, 10, new \ImagickPixel('transparent'));
        $image->setImageFormat('tiff');
        $tiff = $image->getImageBlob();

        $result = (new NormalizeImageFormat())->normalize($tiff);

        self::assertNotNull($result);
        self::assertSame('png', $result['extension']);
        self::assertSame('image/png', $result['mimeType']);
    }

    /**
     * SVG is a vector asset everywhere in this app (logos, backgrounds) and
     * must never be silently rasterized — the caller keeps it as-is.
     */
    public function testRefusesSvgInsteadOfRasterizingIt(): void
    {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';

        self::assertNull((new NormalizeImageFormat())->normalize($svg));
    }

    public function testRefusesBytesThatAreNotAnImage(): void
    {
        self::assertNull((new NormalizeImageFormat())->normalize('this is not a picture'));
        self::assertNull((new NormalizeImageFormat())->normalize(''));
    }

    private static function render(string $format, int $width, int $height): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('#3366cc'));
        $image->setImageFormat($format);

        return $image->getImageBlob();
    }
}
