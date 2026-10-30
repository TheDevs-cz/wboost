<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Print;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Print\ScreenPdfRenderer;
use WBoost\Web\Services\Print\ScreenPdfWriter;
use WBoost\Web\Value\DimensionPreset;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\ScreenPdfPage;
use WBoost\Web\Value\TemplateDimension;

/**
 * @covers \WBoost\Web\Services\Print\ScreenPdfWriter
 * @covers \WBoost\Web\Services\Print\PdfObjects
 * @covers \WBoost\Web\Services\Print\ScreenPdfRenderer
 */
final class ScreenPdfWriterTest extends TestCase
{
    public function testDocumentStructure(): void
    {
        $jpegs = [$this->rgbJpeg('#ff0000'), $this->rgbJpeg('#00ff00')];
        $pdf = (new ScreenPdfWriter())->write(
            [
                new ScreenPdfPage($jpegs[0], 4, 4, 595.2756, 841.8898),
                new ScreenPdfPage($jpegs[1], 4, 4, 810, 1012.5),
            ],
            'Brožura',
            new \DateTimeImmutable('2026-10-30 12:00:00+00:00'),
        );

        self::assertStringStartsWith("%PDF-1.4\n", $pdf);
        // catalog, pages, Info + 3 per page.
        PdfX1aWriterTest::assertXrefPointsAtObjects($pdf, 3 + 2 * 3);
        self::assertStringContainsString('/Type /Pages /Kids [ 4 0 R 7 0 R ] /Count 2', $pdf);
        self::assertStringContainsString('/MediaBox [ 0 0 595.2756 841.8898 ]', $pdf);
        self::assertStringContainsString('/MediaBox [ 0 0 810 1012.5 ]', $pdf);
        self::assertSame(2, substr_count($pdf, '/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode'));
        self::assertStringContainsString('/Title <FEFF', $pdf);
        self::assertStringContainsString('/CreationDate (D:20261030120000Z)', $pdf);
        self::assertMatchesRegularExpression('#/Root 1 0 R /Info 3 0 R /ID \[ <[0-9a-f]{32}> <[0-9a-f]{32}> \]#', $pdf);

        // A screen file: no print furniture at all.
        self::assertStringNotContainsString('/TrimBox', $pdf);
        self::assertStringNotContainsString('/BleedBox', $pdf);
        self::assertStringNotContainsString('/OutputIntent', $pdf);
        self::assertStringNotContainsString('/DeviceCMYK', $pdf);
        self::assertStringNotContainsString('/Separation', $pdf);

        foreach ($jpegs as $jpeg) {
            self::assertSame(1, substr_count($pdf, $jpeg));
        }
    }

    public function testRefusesAnEmptyDocument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ScreenPdfWriter())->write([], 'Test', new \DateTimeImmutable());
    }

    public function testPageAndRasterSizes(): void
    {
        $a4 = new TemplateDimension(DimensionUnit::Mm, 210, 297);
        [$width, $height] = ScreenPdfRenderer::pageSizePt($a4);
        self::assertEqualsWithDelta(595.276, $width, 0.001);
        self::assertEqualsWithDelta(841.89, $height, 0.001);
        // 150 ppi of the trim: 210 mm = 1240 px.
        self::assertSame([1240, 1754], ScreenPdfRenderer::rasterSize($a4));

        $cm = new TemplateDimension(DimensionUnit::Cm, 10, 15);
        self::assertEqualsWithDelta(283.465, ScreenPdfRenderer::pageSizePt($cm)[0], 0.001);
        self::assertSame([591, 886], ScreenPdfRenderer::rasterSize($cm));

        // px keeps its pixels; 1 px = 0.75 pt.
        $post = TemplateDimension::fromPreset(DimensionPreset::InstagramPost);
        self::assertSame([(float) $post->width() * 0.75, (float) $post->height() * 0.75], ScreenPdfRenderer::pageSizePt($post));
        self::assertSame([$post->width(), $post->height()], ScreenPdfRenderer::rasterSize($post));
    }

    public function testPageRasterIsAnRgbJpegFlattenedOnWhite(): void
    {
        // A grey-only, half-transparent PNG: must still come out as a
        // 3-component (RGB) JPEG, downscaled, the transparency on white.
        $image = new \Imagick();
        $image->newImage(20, 10, new \ImagickPixel('rgba(0,0,0,0)'));
        $image->setImageFormat('png');

        [$jpeg, $width, $height] = ScreenPdfRenderer::toJpeg($image->getImageBlob(), 10, 5);

        self::assertSame([10, 5], [$width, $height]);
        $sof = strpos($jpeg, "\xFF\xC0");
        self::assertNotFalse($sof);
        self::assertSame(3, ord($jpeg[$sof + 9]), 'three colour components');

        $decoded = new \Imagick();
        $decoded->readImageBlob($jpeg);
        $pixel = $decoded->getImagePixelColor(5, 2)->getColor();
        self::assertGreaterThan(250, $pixel['r'], 'transparency is flattened on white');
    }

    private function rgbJpeg(string $color): string
    {
        $image = new \Imagick();
        $image->newImage(4, 4, new \ImagickPixel($color));
        $image->setImageFormat('jpeg');

        return $image->getImageBlob();
    }
}
