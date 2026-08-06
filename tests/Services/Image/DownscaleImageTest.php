<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Image;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Image\DownscaleImage;
use WBoost\Web\Value\RenderImageFormat;

/**
 * The bound on {@see \WBoost\Web\Mcp\Tool\RenderVariantTool}'s preview, tested
 * where it is actually decidable: the MCP tool suite runs against
 * {@see \WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer}, whose render
 * is a 1×1 pixel, so nothing there can prove that a 2480 × 3508 A4 render comes
 * back small. This does, on real bytes.
 */
final class DownscaleImageTest extends TestCase
{
    /**
     * A4 at 300 DPI — the real size the renderer hands back for a print
     * variant, and the case the bound exists for.
     */
    public function testScalesAnA4RenderDownToTheLongEdgeKeepingItsRatio(): void
    {
        $result = (new DownscaleImage())->toLongEdge(
            self::render('png', 2480, 3508),
            1200,
            RenderImageFormat::Webp,
        );

        self::assertTrue($result['downscaled']);
        self::assertSame(1200, $result['height'], 'The LONGER side is what the bound applies to.');
        self::assertSame(848, $result['width'], '2480 / 3508 × 1200, rounded — the ratio is preserved.');

        $size = getimagesizefromstring($result['contents']);
        self::assertNotFalse($size);
        self::assertSame(IMAGETYPE_WEBP, $size[2], 'The bytes must be the format the caller declared.');
        self::assertSame([848, 1200], [$size[0], $size[1]]);
    }

    /**
     * The landscape mirror: the bound is on the long edge whichever one it is.
     */
    public function testScalesByTheLongerSideWhenTheRenderIsLandscape(): void
    {
        $result = (new DownscaleImage())->toLongEdge(
            self::render('png', 3508, 2480),
            1200,
            RenderImageFormat::Webp,
        );

        self::assertSame(1200, $result['width']);
        self::assertSame(848, $result['height']);
    }

    /**
     * Every Instagram preset is 1080 px on its long edge, so the common case
     * must cost nothing at all — not a re-encode, not a decode.
     */
    public function testLeavesAPictureWithinTheBoundByteForByte(): void
    {
        $webp = self::render('webp', 1080, 1080);

        $result = (new DownscaleImage())->toLongEdge($webp, 1200, RenderImageFormat::Webp);

        self::assertFalse($result['downscaled']);
        self::assertSame($webp, $result['contents']);
        self::assertSame(1080, $result['width']);
        self::assertSame(1080, $result['height']);
    }

    /**
     * Never scales UP: a small render stays small rather than being blown up to
     * the bound.
     */
    public function testNeverEnlarges(): void
    {
        $result = (new DownscaleImage())->toLongEdge(self::render('webp', 40, 20), 1200, RenderImageFormat::Webp);

        self::assertFalse($result['downscaled']);
        self::assertSame(40, $result['width']);
        self::assertSame(20, $result['height']);
    }

    /**
     * A transparent render is legal — a layer-mode variant without a background
     * exports with real alpha — so the resize must not flatten it onto black.
     */
    public function testKeepsTransparency(): void
    {
        $source = new \Imagick();
        $source->newImage(2000, 1000, new \ImagickPixel('transparent'));
        $source->setImageFormat('png');

        $result = (new DownscaleImage())->toLongEdge($source->getImageBlob(), 500, RenderImageFormat::Webp);

        self::assertTrue($result['downscaled']);

        $resized = new \Imagick();
        $resized->readImageBlob($result['contents']);

        self::assertLessThan(
            0.01,
            $resized->getImagePixelColor(10, 10)->getColorValue(\Imagick::COLOR_ALPHA),
            'The resized preview lost its alpha channel.',
        );
    }

    /**
     * Bytes that are not a picture at all come back untouched with no size:
     * a caller asked for a preview, and a broken render is the renderer's
     * problem to report, not something to turn into an exception here.
     */
    public function testPassesUnreadableBytesThroughWithoutASize(): void
    {
        $result = (new DownscaleImage())->toLongEdge('not an image', 1200, RenderImageFormat::Webp);

        self::assertSame('not an image', $result['contents']);
        self::assertNull($result['width']);
        self::assertNull($result['height']);
        self::assertFalse($result['downscaled']);
    }

    private static function render(string $format, int $width, int $height): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('#3366cc'));
        $image->setImageFormat($format);

        return $image->getImageBlob();
    }
}
