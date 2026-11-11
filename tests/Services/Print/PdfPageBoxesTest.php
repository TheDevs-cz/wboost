<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Print;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Exceptions\PdfStructureNotSupported;
use WBoost\Web\Services\Print\PdfPageBoxes;
use WBoost\Web\Services\Print\ScreenPdfWriter;
use WBoost\Web\Value\ScreenPdfPage;

/**
 * @covers \WBoost\Web\Services\Print\PdfPageBoxes
 */
final class PdfPageBoxesTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'pdf-boxes-');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testBoxesAreAppendedAsAnIncrementalUpdateAnchoredAtTheTopLeft(): void
    {
        // Two pages of 600 × 400 pt — a fraction larger than the 595.5 × 396 pt asked for,
        // the way Chromium prints on the next whole point up.
        $original = $this->pdf(600, 400, 2);
        file_put_contents($this->file, $original);

        $pages = PdfPageBoxes::apply($this->file, 595.5, 396.0, 36.0, 22.0, [
            'title' => 'Obecní kalendář',
            'creator' => 'WBoost',
            'createdAt' => new \DateTimeImmutable('2026-11-11 12:00:00+00:00'),
        ]);

        self::assertSame(2, $pages);

        $updated = (string) file_get_contents($this->file);
        // Not one byte of the original is touched.
        self::assertNotSame('', $original);
        self::assertStringStartsWith($original, $updated);
        $appended = substr($updated, strlen($original));

        // The content is anchored at the top (y = 400): the exact page is measured down from there.
        self::assertSame(2, substr_count($appended, '/MediaBox [ 0 4 595.5 400 ] /CropBox [ 0 4 595.5 400 ] /BleedBox [ 22 26 573.5 378 ] /TrimBox [ 36 40 559.5 364 ]'));
        // The page keeps everything else it had — once.
        self::assertSame(2, substr_count($appended, '/Type /Page '));
        self::assertSame(2, substr_count($appended, '/Contents '));
        self::assertSame(2, substr_count($appended, '/MediaBox'));
        self::assertStringContainsString('/Title <FEFF', $appended);
        self::assertStringContainsString('/Creator (WBoost)', $appended);
        self::assertStringContainsString('/CreationDate (D:20261111120000Z)', $appended);

        // The new trailer points back at the old cross-reference table and keeps the document id.
        self::assertSame(1, preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $original, $old));
        self::assertMatchesRegularExpression('~trailer\n<< /Size \d+ /Root 1 0 R /Info \d+ 0 R /Prev ' . $old[1] . ' /ID \[ <[0-9a-f]{32}> <[0-9a-f]{32}> \] >>\nstartxref\n\d+\n%%EOF\n$~', $appended);

        // Every offset of the new cross-reference section points at its object.
        self::assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF\n$/', $updated, $new));
        $xref = substr($updated, (int) $new[1]);
        self::assertStringStartsWith("xref\n", $xref);
        preg_match_all('/^(\d+) (\d+)\n((?:\d{10} 00000 n \n)+)/m', $xref, $sections, PREG_SET_ORDER);
        $checked = 0;

        foreach ($sections as $section) {
            foreach (explode("\n", trim($section[3])) as $index => $entry) {
                $number = (int) $section[1] + $index;
                self::assertStringStartsWith($number . ' 0 obj', substr($updated, (int) substr($entry, 0, 10), 20), 'object ' . $number);
                ++$checked;
            }
        }

        self::assertSame(3, $checked, 'two pages + the Info dictionary');
    }

    /**
     * Ghostscript reads the update: rendered by its TrimBox, a page is
     * exactly the trim size.
     */
    public function testAReaderSeesTheNewBoxes(): void
    {
        $gs = (new ExecutableFinder())->find('gs');

        if ($gs === null) {
            self::markTestSkipped('Ghostscript is not installed.');
        }

        file_put_contents($this->file, $this->pdf(600, 400, 2));
        PdfPageBoxes::apply($this->file, 596.0, 396.0, 48.0, 24.0);

        $sizes = [];

        foreach (['-dUseCropBox' => [596, 396], '-dUseTrimBox' => [500, 300], '-dUseBleedBox' => [548, 348]] as $flag => $expected) {
            $out = $this->file . '.png';
            (new Process([$gs, '-q', '-dBATCH', '-dNOPAUSE', '-dSAFER', $flag, '-sDEVICE=pnggray', '-r72', '-dLastPage=1', '-o', $out, $this->file]))->mustRun();
            $size = getimagesize($out);
            @unlink($out);
            self::assertIsArray($size);
            $sizes[$flag] = [$size[0], $size[1]];
            self::assertSame($expected, $sizes[$flag], $flag);
        }
    }

    public function testAPageSmallerThanAskedOrAnUnknownStructureLeavesTheFileUntouched(): void
    {
        $original = $this->pdf(600, 400, 1);

        $cases = [
            'the page is smaller than the size asked for' => [$original, 601.0, 400.0],
            'a cross-reference stream' => [preg_replace('/xref\n0 /', "9 0 obj\n<< /Type /XRef >>\n0 ", $original, 1), 600.0, 400.0],
            'an earlier update' => [str_replace("trailer\n<< ", "trailer\n<< /Prev 10 ", $original), 600.0, 400.0],
            'encryption' => [str_replace("trailer\n<< ", "trailer\n<< /Encrypt 9 0 R ", $original), 600.0, 400.0],
            'no startxref' => ['%PDF-1.4' . str_repeat(' ', 100), 600.0, 400.0],
        ];

        foreach ($cases as $name => [$bytes, $width, $height]) {
            self::assertIsString($bytes, $name);
            file_put_contents($this->file, $bytes);

            try {
                PdfPageBoxes::apply($this->file, $width, $height, 0.0, 0.0);
                self::fail($name . ': expected a refusal');
            } catch (PdfStructureNotSupported) {
                self::assertSame($bytes, file_get_contents($this->file), $name);
            }
        }

        // …and a second update of an updated file is refused too (it has /Prev now).
        file_put_contents($this->file, $original);
        PdfPageBoxes::apply($this->file, 600.0, 400.0, 0.0, 0.0);
        $once = file_get_contents($this->file);

        try {
            PdfPageBoxes::apply($this->file, 600.0, 400.0, 0.0, 0.0);
            self::fail('expected a refusal');
        } catch (PdfStructureNotSupported) {
            self::assertSame($once, file_get_contents($this->file));
        }
    }

    private function pdf(float $width, float $height, int $pages): string
    {
        $image = new \Imagick();
        $image->newImage(4, 4, new \ImagickPixel('#3366cc'));
        $image->setImageFormat('jpeg');
        $jpeg = $image->getImageBlob();
        $image->clear();

        return (new ScreenPdfWriter())->write(
            array_fill(0, $pages, new ScreenPdfPage($jpeg, 4, 4, $width, $height)),
            'Test',
            new \DateTimeImmutable('2026-11-11 12:00:00+00:00'),
        );
    }
}
