<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Print;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Print\PdfX1aWriter;
use WBoost\Web\Value\DimensionPreset;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\PrintPageGeometry;
use WBoost\Web\Value\PrintRaster;
use WBoost\Web\Value\TemplateDimension;

/**
 * @covers \WBoost\Web\Services\Print\PdfX1aWriter
 * @covers \WBoost\Web\Value\PrintPageGeometry
 */
final class PdfX1aWriterTest extends TestCase
{
    public function testGeometryFollowsThePrintSetup(): void
    {
        $a4 = PrintPageGeometry::fromDimension((new TemplateDimension(DimensionUnit::Mm, 210, 297))->withPrintSettings(5, true));

        self::assertEqualsWithDelta(595.276, $a4->trimWidth, 0.001);
        self::assertEqualsWithDelta(14.173, $a4->bleed, 0.001);
        // A 5 mm bleed pushes the marks out past it.
        self::assertEqualsWithDelta(14.173, $a4->markOffset(), 0.001);
        self::assertEqualsWithDelta(14.173 + 27, $a4->margin(), 0.001);

        $plain = PrintPageGeometry::fromDimension(new TemplateDimension(DimensionUnit::Mm, 210, 297));
        self::assertSame(0.0, $plain->margin(), 'no bleed, no marks: the page IS the trim');
        self::assertSame($plain->trimBox(), $plain->mediaBox());

        // Social formats map to paper at 300 DPI: 1080 px = 259.2 pt.
        $post = PrintPageGeometry::fromDimension(TemplateDimension::fromPreset(DimensionPreset::InstagramPost));
        self::assertEqualsWithDelta(259.2, $post->trimWidth, 0.001);
    }

    public function testDocumentStructure(): void
    {
        $geometry = new PrintPageGeometry(100, 200, 8.504, false);
        $pdf = (new PdfX1aWriter())->write(
            new PrintRaster($this->cmykJpeg(), 4, 4),
            $geometry,
            'ICCPROFILEBYTES',
            'Plakát – A4',
            new \DateTimeImmutable('2026-10-30 12:00:00', new \DateTimeZone('Europe/Prague')),
        );

        self::assertStringStartsWith("%PDF-1.3\n", $pdf);
        self::assertStringContainsString('/GTS_PDFXVersion (PDF/X-1:2001) /GTS_PDFXConformance (PDF/X-1a:2001)', $pdf);
        self::assertStringContainsString('/Trapped /False', $pdf);
        self::assertStringContainsString('/CreationDate (D:20261030120000+01\'00\')', $pdf);
        // Non-ASCII title travels as UTF-16BE.
        self::assertStringContainsString('/Title <FEFF0050006C0061006B00E1007400202013002000410034>', $pdf);
        self::assertStringContainsString('/MediaBox [ 0 0 117.008 217.008 ]', $pdf);
        self::assertStringContainsString('/TrimBox [ 8.504 8.504 108.504 208.504 ]', $pdf);
        self::assertStringContainsString('/BleedBox [ 0 0 117.008 217.008 ]', $pdf);
        self::assertStringNotContainsString('/CS0 CS', $this->inflatedContent($pdf), 'no crop marks requested');
        self::assertMatchesRegularExpression('#/ID \[ <[0-9a-f]{32}> <[0-9a-f]{32}> \]#', $pdf);

        // xref offsets point at the objects.
        self::assertSame(1, preg_match('/startxref\n(\d+)\n/', $pdf, $m));
        self::assertStringStartsWith('xref', substr($pdf, (int) $m[1]));
        preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $offsets);
        foreach ($offsets[1] as $index => $offset) {
            self::assertStringStartsWith(($index + 1) . ' 0 obj', substr($pdf, (int) $offset));
        }
    }

    public function testCropMarksAreDrawnInRegistrationColour(): void
    {
        $pdf = (new PdfX1aWriter())->write(
            new PrintRaster($this->cmykJpeg(), 4, 4),
            new PrintPageGeometry(100, 200, 8.504, true),
            'ICC',
            'Test',
            new \DateTimeImmutable('2026-10-30 12:00:00+00:00'),
        );

        $content = $this->inflatedContent($pdf);
        self::assertStringContainsString('/CS0 CS', $content);
        self::assertStringContainsString("1.25 w 0 SCN\n", $content, 'white halo first');
        self::assertStringContainsString("0.25 w 1 SCN\n", $content, 'then the registration hairline');
        // 8 marks per pass (2 per corner).
        self::assertSame(16, substr_count($content, ' l'));
        self::assertStringContainsString('/Separation /All /DeviceCMYK', $pdf);
    }

    private function cmykJpeg(): string
    {
        $image = new \Imagick();
        $image->newImage(4, 4, new \ImagickPixel('cmyk(100%,0%,0%,0%)'));
        $image->setImageColorspace(\Imagick::COLORSPACE_CMYK);
        $image->setImageFormat('jpeg');

        return $image->getImageBlob();
    }

    private function inflatedContent(string $pdf): string
    {
        self::assertSame(1, preg_match('/6 0 obj\n<< \/Filter \/FlateDecode \/Length (\d+) >>\nstream\n/', $pdf, $m, PREG_OFFSET_CAPTURE));
        $start = $m[0][1] + strlen($m[0][0]);

        return (string) gzuncompress(substr($pdf, $start, (int) $m[1][0]));
    }
}
