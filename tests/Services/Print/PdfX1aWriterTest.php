<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Print;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Print\PdfX1aWriter;
use WBoost\Web\Value\DimensionPreset;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\PrintPage;
use WBoost\Web\Value\PrintPageGeometry;
use WBoost\Web\Value\PrintRaster;
use WBoost\Web\Value\TemplateDimension;

/**
 * @covers \WBoost\Web\Services\Print\PdfX1aWriter
 * @covers \WBoost\Web\Services\Print\PdfObjects
 * @covers \WBoost\Web\Value\PrintPageGeometry
 */
final class PdfX1aWriterTest extends TestCase
{
    /**
     * Two separate things around the trim: the BLEED (artwork past the cut,
     * the designer's "Spadávka", default 3 mm) and, outside it, a FIXED 5 mm
     * crop-mark margin. Page = trim + 2 × bleed + 2 × 5 mm.
     */
    public function testGeometryFollowsThePrintSetup(): void
    {
        $voucher = PrintPageGeometry::fromDimension((new TemplateDimension(DimensionUnit::Mm, 210, 99))->withPrintSettings(3, true));

        self::assertEqualsWithDelta(595.276, $voucher->trimWidth, 0.001);
        self::assertEqualsWithDelta(8.504, $voucher->bleed, 0.001);
        // The marks start where the bleed ends — never on artwork…
        self::assertEqualsWithDelta(8.504, $voucher->markOffset(), 0.001);
        // …and live in the fixed 5 mm margin outside it.
        self::assertEqualsWithDelta(5.0, self::ptToMm($voucher->margin() - $voucher->bleed), 0.0001);
        self::assertBoxMm([0, 0, 226, 115], $voucher->mediaBox());
        self::assertBoxMm([5, 5, 221, 110], $voucher->bleedBox());
        self::assertBoxMm([8, 8, 218, 107], $voucher->trimBox());

        // A bigger bleed grows the page; the mark margin stays 5 mm.
        $wide = PrintPageGeometry::fromDimension((new TemplateDimension(DimensionUnit::Mm, 210, 99))->withPrintSettings(5, true));
        self::assertBoxMm([0, 0, 230, 119], $wide->mediaBox());
        self::assertBoxMm([5, 5, 225, 114], $wide->bleedBox());

        // No marks = no mark margin: the page is the bleed box.
        $noMarks = PrintPageGeometry::fromDimension((new TemplateDimension(DimensionUnit::Mm, 210, 99))->withPrintSettings(3, false));
        self::assertBoxMm([0, 0, 216, 105], $noMarks->mediaBox());
        self::assertSame($noMarks->bleedBox(), $noMarks->mediaBox());

        $plain = PrintPageGeometry::fromDimension(new TemplateDimension(DimensionUnit::Mm, 210, 297));
        self::assertSame(0.0, $plain->margin(), 'no bleed, no marks: the page IS the trim');
        self::assertSame($plain->trimBox(), $plain->mediaBox());

        // Social formats map to paper at 300 DPI: 1080 px = 259.2 pt.
        $post = PrintPageGeometry::fromDimension(TemplateDimension::fromPreset(DimensionPreset::InstagramPost));
        self::assertEqualsWithDelta(259.2, $post->trimWidth, 0.001);
    }

    /**
     * @param array{float|int, float|int, float|int, float|int} $expectedMm
     * @param array{float, float, float, float} $boxPt
     */
    private static function assertBoxMm(array $expectedMm, array $boxPt): void
    {
        self::assertEqualsWithDelta($expectedMm, array_map(self::ptToMm(...), $boxPt), 0.0001);
    }

    private static function ptToMm(float $pt): float
    {
        return $pt / 72 * 25.4;
    }

    public function testDocumentStructure(): void
    {
        $geometry = new PrintPageGeometry(100, 200, 8.504, false);
        $pdf = (new PdfX1aWriter())->write(
            [new PrintPage(new PrintRaster($this->cmykJpeg(), 4, 4), $geometry)],
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

        self::assertXrefPointsAtObjects($pdf, 8);
    }

    /**
     * The single-page file keeps the exact shape it had before the writer
     * went multi-page: the same 8 objects (catalog, pages, ICC, registration
     * colour space, Info, page, image, content), one page, one output intent.
     */
    public function testSinglePageStructureIsUnchanged(): void
    {
        $pdf = (new PdfX1aWriter())->write(
            [new PrintPage(new PrintRaster($this->cmykJpeg(), 4, 4), new PrintPageGeometry(100, 200, 8.504, true))],
            'ICC',
            'Test',
            new \DateTimeImmutable('2026-10-30 12:00:00+00:00'),
        );

        self::assertXrefPointsAtObjects($pdf, 8);
        self::assertStringContainsString('/Type /Pages /Kids [ 6 0 R ] /Count 1', $pdf);
        self::assertSame(1, substr_count($pdf, '/Type /Page '));
        self::assertSame(1, substr_count($pdf, '/Type /OutputIntent'));
        self::assertSame(1, substr_count($pdf, '/Subtype /Image'));
        self::assertStringContainsString('/DestOutputProfile 3 0 R', $pdf);
        self::assertStringContainsString('/XObject << /Im0 7 0 R >> /ColorSpace << /CS0 4 0 R >> >> /Contents 8 0 R', $pdf);
        self::assertStringContainsString('/Root 1 0 R /Info 5 0 R', $pdf);
        self::assertStringContainsString('/Trapped /False', $pdf);
    }

    public function testMultiPageStructure(): void
    {
        $jpegs = [$this->cmykJpeg('cmyk(100%,0%,0%,0%)'), $this->cmykJpeg('cmyk(0%,100%,0%,0%)'), $this->cmykJpeg('cmyk(0%,0%,100%,0%)')];
        $pages = [
            new PrintPage(new PrintRaster($jpegs[0], 4, 4), new PrintPageGeometry(100, 200, 8.504, true)),
            new PrintPage(new PrintRaster($jpegs[1], 4, 4), new PrintPageGeometry(100, 200, 8.504, true)),
            // A different size + no marks: every page carries its own boxes.
            new PrintPage(new PrintRaster($jpegs[2], 4, 4), new PrintPageGeometry(300, 150, 0, false)),
        ];
        $createdAt = new \DateTimeImmutable('2026-10-30 12:00:00+00:00');
        $pdf = (new PdfX1aWriter())->write($pages, 'ICCPROFILEBYTES', 'Brožura', $createdAt);

        self::assertStringStartsWith("%PDF-1.3\n", $pdf);
        // 5 shared objects + 3 per page.
        self::assertXrefPointsAtObjects($pdf, 5 + 3 * 3);
        self::assertStringContainsString('/Type /Pages /Kids [ 6 0 R 9 0 R 12 0 R ] /Count 3', $pdf);
        self::assertSame(3, substr_count($pdf, '/Type /Page '));
        self::assertSame(3, substr_count($pdf, '/Subtype /Image'));
        self::assertSame(3, substr_count($pdf, '/MediaBox '));
        self::assertSame(3, substr_count($pdf, '/TrimBox '));
        self::assertSame(3, substr_count($pdf, '/BleedBox '));
        self::assertSame(2, substr_count($pdf, '/MediaBox [ 0 0 145.3545 245.3545 ]'));
        self::assertSame(1, substr_count($pdf, '/MediaBox [ 0 0 300 150 ]'));
        // ONE output intent, ONE embedded profile, ONE Info.
        self::assertSame(1, substr_count($pdf, '/Type /OutputIntent'));
        self::assertSame(1, substr_count($pdf, '/N 4 /Filter /FlateDecode'));
        self::assertSame(1, substr_count($pdf, '/GTS_PDFXConformance'));
        self::assertSame(1, substr_count($pdf, '/Separation /All'));
        foreach ($jpegs as $jpeg) {
            self::assertSame(1, substr_count($pdf, $jpeg));
        }

        // Each page draws its own image; marks only where requested.
        self::assertStringContainsString('/CS0 CS', $this->inflatedContent($pdf, 8));
        self::assertStringContainsString('/CS0 CS', $this->inflatedContent($pdf, 11));
        self::assertStringNotContainsString('/CS0 CS', $this->inflatedContent($pdf, 14));

        // The ID covers every page: changing page 3 alone changes it.
        $other = (new PdfX1aWriter())->write([$pages[0], $pages[1], $pages[0]], 'ICCPROFILEBYTES', 'Brožura', $createdAt);
        self::assertNotSame($this->documentId($pdf), $this->documentId($other));
    }

    public function testRefusesAnEmptyDocument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PdfX1aWriter())->write([], 'ICC', 'Test', new \DateTimeImmutable());
    }

    /**
     * The xref has one contiguous subsection 0…$objectCount and every offset
     * lands on `N 0 obj`.
     */
    public static function assertXrefPointsAtObjects(string $pdf, int $objectCount): void
    {
        self::assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $m));
        $xref = substr($pdf, (int) $m[1]);
        self::assertStringStartsWith("xref\n0 " . ($objectCount + 1) . "\n0000000000 65535 f \n", $xref);
        self::assertStringContainsString('/Size ' . ($objectCount + 1) . ' ', $pdf);

        preg_match_all('/^(\d{10}) 00000 n $/m', $xref, $offsets);
        self::assertCount($objectCount, $offsets[1]);
        foreach ($offsets[1] as $index => $offset) {
            self::assertStringStartsWith(($index + 1) . " 0 obj\n", substr($pdf, (int) $offset));
        }
    }

    private function documentId(string $pdf): string
    {
        self::assertSame(1, preg_match('#/ID \[ <([0-9a-f]{32})>#', $pdf, $m));

        return $m[1];
    }

    public function testCropMarksAreDrawnInRegistrationColour(): void
    {
        $pdf = (new PdfX1aWriter())->write(
            [new PrintPage(new PrintRaster($this->cmykJpeg(), 4, 4), new PrintPageGeometry(100, 200, 8.504, true))],
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

        // Every mark lies in the 5 mm margin OUTSIDE the bleed box: it starts
        // on the bleed edge and ends on the media edge, on the trim line.
        $geometry = new PrintPageGeometry(100, 200, 8.504, true);
        [$bx0, $by0, $bx1, $by1] = $geometry->bleedBox();
        [, , $mx1, $my1] = $geometry->mediaBox();
        preg_match_all('/^(\S+) (\S+) m (\S+) (\S+) l$/m', $content, $segments, PREG_SET_ORDER);
        self::assertCount(16, $segments);
        foreach ($segments as [, $x0, $y0, $x1, $y1]) {
            foreach ([[(float) $x0, (float) $y0], [(float) $x1, (float) $y1]] as [$x, $y]) {
                self::assertTrue($x >= -0.001 && $x <= $mx1 + 0.001 && $y >= -0.001 && $y <= $my1 + 0.001, 'mark stays on the media');
                $insideBleed = $x > $bx0 + 0.001 && $x < $bx1 - 0.001 && $y > $by0 + 0.001 && $y < $by1 - 0.001;
                self::assertFalse($insideBleed, sprintf('mark point %s,%s overlaps the artwork', $x, $y));
            }
            $length = hypot((float) $x1 - (float) $x0, (float) $y1 - (float) $y0);
            self::assertEqualsWithDelta(PrintPageGeometry::mmToPt(5), $length, 0.001, 'a mark spans the whole 5 mm margin');
        }
        self::assertStringContainsString('/Separation /All /DeviceCMYK', $pdf);
    }

    private function cmykJpeg(string $color = 'cmyk(100%,0%,0%,0%)'): string
    {
        $image = new \Imagick();
        $image->newImage(4, 4, new \ImagickPixel($color));
        $image->setImageColorspace(\Imagick::COLORSPACE_CMYK);
        $image->setImageFormat('jpeg');

        return $image->getImageBlob();
    }

    private function inflatedContent(string $pdf, int $object = 8): string
    {
        self::assertSame(1, preg_match('/(?<!\d)' . $object . ' 0 obj\n<< \/Filter \/FlateDecode \/Length (\d+) >>\nstream\n/', $pdf, $m, PREG_OFFSET_CAPTURE));
        $start = $m[0][1] + strlen($m[0][0]);

        return (string) gzuncompress(substr($pdf, $start, (int) $m[1][0]));
    }
}
