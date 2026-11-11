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

    /**
     * A phone holds the sensor sideways and records "which way is up" as an
     * EXIF flag. getimagesizefromstring() ignores the flag, browsers honour
     * it — so the recorded size and the picture disagreed (a portrait photo
     * drawn into a landscape box). The rotation is baked into the pixels.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideOrientedFormats')]
    public function testBakesTheExifOrientationIntoThePixels(string $format, string $extension, string $mimeType): void
    {
        $sideways = self::oriented($format, 40, 30, 6);
        $stored = getimagesizefromstring($sideways);
        self::assertNotFalse($stored);
        self::assertSame([40, 30], [$stored[0], $stored[1]], 'The fixture is stored landscape.');

        $result = (new NormalizeImageFormat())->normalize($sideways);

        self::assertNotNull($result);
        self::assertSame($extension, $result['extension']);
        self::assertSame($mimeType, $result['mimeType']);
        self::assertSame([30, 40], [$result['width'], $result['height']]);

        $size = getimagesizefromstring($result['contents']);
        self::assertNotFalse($size);
        self::assertSame([30, 40], [$size[0], $size[1]]);

        // …and the flag is gone, so nobody rotates the picture a second time.
        $again = (new NormalizeImageFormat())->normalize($result['contents']);
        self::assertNotNull($again);
        self::assertSame($result['contents'], $again['contents']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideOrientedFormats(): iterable
    {
        yield 'jpeg' => ['jpeg', 'jpg', 'image/jpeg'];
        yield 'png' => ['png', 'png', 'image/png'];
        yield 'webp' => ['webp', 'webp', 'image/webp'];
    }

    public function testAnUprightPictureWithExifStaysByteForByte(): void
    {
        $upright = self::oriented('jpeg', 40, 30, 1);

        $result = (new NormalizeImageFormat())->normalize($upright);

        self::assertNotNull($result);
        self::assertSame($upright, $result['contents']);
        self::assertSame([40, 30], [$result['width'], $result['height']]);
    }

    public function testKeepsTheColourProfileWhenItRotates(): void
    {
        $sideways = self::oriented('jpeg', 40, 30, 6, (string) file_get_contents(__DIR__ . '/../../../resources/icc/sRGB-v4.icc'));

        $result = (new NormalizeImageFormat())->normalize($sideways);

        self::assertNotNull($result);
        self::assertSame(30, $result['width']);

        $rotated = new \Imagick();
        $rotated->readImageBlob($result['contents']);
        self::assertSame(file_get_contents(__DIR__ . '/../../../resources/icc/sRGB-v4.icc'), $rotated->getImageProfiles('icc', true)['icc'] ?? null);
    }

    /**
     * The SVG sniff used to read the first kilobyte only: an SVG behind a long
     * comment fell through to ImageMagick, which RENDERS it (Inkscape / rsvg
     * on the server, with the file's own references). Text never reaches the
     * decoder — neither SVG nor ImageMagick's own script formats.
     */
    public function testNeverHandsTextToImageMagick(): void
    {
        $padding = '<!--' . str_repeat('x', 3000) . '-->';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="10"><rect width="20" height="10"/></svg>';

        self::assertNull((new NormalizeImageFormat())->normalize('<?xml version="1.0"?>' . $padding . $svg));
        self::assertNull((new NormalizeImageFormat())->normalize($padding . $svg));
        self::assertNull((new NormalizeImageFormat())->normalize("push graphic-context\nviewbox 0 0 10 10\nfill 'red'\nrectangle 0,0 10,10\npop graphic-context"));

        self::assertTrue(NormalizeImageFormat::looksLikeSvg($padding . $svg));
        self::assertFalse(NormalizeImageFormat::looksLikeSvg(self::render('png', 4, 4)));
    }

    /**
     * A picture whose header announces more pixels than the app decodes is
     * not decoded: a transcode candidate is refused, an oriented one passes
     * through unrotated.
     */
    public function testDoesNotDecodeAboveThePixelLimit(): void
    {
        $image = new \Imagick();
        $image->newImage(10_000, 7_000, new \ImagickPixel('white'));
        $image->setImageFormat('tiff');
        $image->setImageCompression(\Imagick::COMPRESSION_ZIP);
        $tiff = $image->getImageBlob();
        $image->clear();

        self::assertNull((new NormalizeImageFormat())->normalize($tiff));
    }

    /**
     * @param int<1, 8> $orientation
     */
    private static function oriented(string $format, int $width, int $height, int $orientation, null|string $icc = null): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('#3366cc'));
        $image->setImageFormat($format);
        // A minimal EXIF block: one IFD holding the Orientation tag (0x0112).
        $tiff = "II*\0" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, $orientation, 0) . pack('V', 0);
        $image->profileImage('exif', "Exif\0\0" . $tiff);
        $image->setImageOrientation($orientation);

        if ($icc !== null) {
            $image->profileImage('icc', $icc);
        }

        $bytes = $image->getImageBlob();
        $image->clear();

        return $bytes;
    }

    private static function render(string $format, int $width, int $height): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('#3366cc'));
        $image->setImageFormat($format);

        return $image->getImageBlob();
    }
}
