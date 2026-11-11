<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Print;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Exceptions\PdfStructureNotSupported;
use WBoost\Web\Services\Print\PdfChunkMerger;
use WBoost\Web\Services\Print\PdfObjects;

/**
 * @covers \WBoost\Web\Services\Print\PdfChunkMerger
 */
final class PdfChunkMergerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pdf-merge-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testChunksBecomeOneFileWithBoxesAndSharedStreamsWrittenOnce(): void
    {
        $shared = str_repeat('SHARED-PICTURE-BYTES ', 400);
        $a = $this->chunk('a', ['first', 'second'], $shared);
        $b = $this->chunk('b', ['third'], $shared);
        $c = $this->chunk('c', ['fourth'], $shared);
        $target = $this->directory . '/merged.pdf';
        $steps = [];

        $pages = PdfChunkMerger::merge(
            [$a, $b, $c],
            $target,
            ['width' => 595.5, 'height' => 396.0, 'trimInset' => 36.0, 'bleedInset' => 22.0],
            ['title' => 'Obecní kalendář', 'creator' => 'WBoost', 'createdAt' => new \DateTimeImmutable('2026-11-11 12:00:00+00:00')],
            false,
            static function (int $done, int $total) use (&$steps): void {
                $steps[] = [$done, $total];
            },
        );

        self::assertSame(4, $pages);
        self::assertSame([[1, 3], [2, 3], [3, 3]], $steps);
        self::assertFileExists($a, 'inputs stay unless they are consumed');

        $pdf = (string) file_get_contents($target);
        self::assertStringStartsWith("%PDF-1.4\n", $pdf);

        // One page tree: the new root holds the three chunks' own roots, which point back at it.
        self::assertSame(1, preg_match('~1 0 obj\n<< /Type /Pages /Kids \[ (\d+) 0 R (\d+) 0 R (\d+) 0 R \] /Count 4 >>~', $pdf, $root));
        self::assertSame(3, substr_count($pdf, '<< /Parent 1 0 R /Type /Pages'));
        self::assertSame(1, substr_count($pdf, '/Type /Catalog'), 'the chunks\' catalogs are not carried over');
        self::assertSame(1, substr_count($pdf, '/Title <FEFF'), 'one Info dictionary — ours');
        self::assertStringNotContainsString('/Title (chunk', $pdf);

        // Every page got its boxes, measured down from the top of the page Chromium printed on.
        self::assertSame(4, substr_count($pdf, '/MediaBox [ 0 4 595.5 400 ] /CropBox [ 0 4 595.5 400 ] /BleedBox [ 22 26 573.5 378 ] /TrimBox [ 36 40 559.5 364 ]'));
        self::assertSame(4, substr_count($pdf, '/MediaBox'));

        // The picture every chunk carries is written ONCE; each page's own content is there once too.
        self::assertSame(1, substr_count($pdf, $shared));
        foreach (['first', 'second', 'third', 'fourth'] as $text) {
            self::assertSame(1, substr_count($pdf, '(' . $text . ') Tj'), $text);
        }

        // Text that only LOOKS like a reference — inside a string — is left alone, real ones are renumbered.
        self::assertSame(4, substr_count($pdf, '/Note (see 3 0 R \\) and <4 0 R>)'));
        self::assertSame(4, substr_count($pdf, '/Hex <33203020 52>'));

        // The cross-reference table is contiguous and every entry points at its object.
        self::assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $start));
        $xref = substr($pdf, (int) $start[1]);
        self::assertSame(1, preg_match('/^xref\n0 (\d+)\n0000000000 65535 f \n((?:\d{10} 00000 n \n)+)trailer\n<< \/Size (\d+) \/Root 2 0 R \/Info 3 0 R \/ID \[ <[0-9a-f]{32}> <[0-9a-f]{32}> \] >>/', $xref, $table));
        self::assertSame($table[1], $table[3]);
        $entries = explode("\n", trim($table[2]));
        self::assertCount((int) $table[1] - 1, $entries);

        foreach ($entries as $index => $entry) {
            self::assertStringStartsWith(($index + 1) . ' 0 obj', substr($pdf, (int) substr($entry, 0, 10), 24), 'object ' . ($index + 1));
        }

        // Every reference in the file names an object that exists.
        preg_match_all('/(?<![\w.(<])(\d+) 0 R(?!>)/', (string) preg_replace('~\([^)]*\\\\\)[^)]*\)|stream\n.*?\nendstream~s', '', $pdf), $references);
        foreach (array_unique($references[1]) as $reference) {
            self::assertLessThan((int) $table[1], (int) $reference);
            self::assertGreaterThan(0, (int) $reference);
        }
    }

    /**
     * A real reader agrees: four pages, each rendered at the size of its
     * TrimBox.
     */
    public function testGhostscriptReadsTheMergedFile(): void
    {
        $gs = (new ExecutableFinder())->find('gs');

        if ($gs === null) {
            self::markTestSkipped('Ghostscript is not installed.');
        }

        $target = $this->directory . '/merged.pdf';
        PdfChunkMerger::merge(
            [$this->chunk('a', ['first', 'second'], 'x'), $this->chunk('b', ['third', 'fourth'], 'x')],
            $target,
            ['width' => 596.0, 'height' => 396.0, 'trimInset' => 48.0, 'bleedInset' => 24.0],
        );

        $process = new Process([$gs, '-q', '-dNODISPLAY', '-dBATCH', '-dNOPAUSE', '-dPDFINFO', $target]);
        $process->mustRun();
        $info = $process->getOutput() . $process->getErrorOutput();
        self::assertStringContainsString('File has 4 pages', $info);
        self::assertSame(4, substr_count($info, 'TrimBox: [48'));
        self::assertStringNotContainsString('Error', $info);

        $png = $this->directory . '/page-%d.png';
        (new Process([$gs, '-q', '-dBATCH', '-dNOPAUSE', '-dSAFER', '-dUseTrimBox', '-sDEVICE=pnggray', '-r72', '-o', $png, $target]))->mustRun();

        foreach ([1, 2, 3, 4] as $page) {
            $size = getimagesize(sprintf($png, $page));
            self::assertIsArray($size);
            self::assertSame([500, 300], [$size[0], $size[1]], 'page ' . $page);
        }
    }

    public function testConsumedInputsAreDeletedAndAForeignStructureLeavesNoTarget(): void
    {
        $a = $this->chunk('a', ['first'], 'x');
        $b = $this->chunk('b', ['second'], 'x');
        $target = $this->directory . '/merged.pdf';

        self::assertSame(2, PdfChunkMerger::merge([$a, $b], $target, null, [], true));
        self::assertFileDoesNotExist($a);
        self::assertFileDoesNotExist($b);
        // without boxes the pages keep their own
        self::assertSame(2, substr_count((string) file_get_contents($target), '/MediaBox [ 0 0 600 400 ]'));

        $good = $this->chunk('c', ['third'], 'x');
        $foreign = $this->directory . '/foreign.pdf';
        file_put_contents($foreign, str_replace("xref\n0 ", "9 0 obj\n<< /Type /XRef >>\n0 ", (string) file_get_contents($good)));
        $other = $this->directory . '/other.pdf';

        try {
            PdfChunkMerger::merge([$good, $foreign], $other);
            self::fail('expected a refusal');
        } catch (PdfStructureNotSupported) {
            self::assertFileDoesNotExist($other);
            self::assertFileExists($good);
        }

        $this->expectException(PdfStructureNotSupported::class);
        PdfChunkMerger::merge([], $other);
    }

    /**
     * A file shaped like Chromium's: Info first, the shared picture, per page
     * a content stream and the page, the page tree and the catalog last — so
     * pages refer FORWARD to their parent.
     *
     * @param list<string> $texts one page per text
     */
    private function chunk(string $name, array $texts, string $shared): string
    {
        $pdf = new PdfObjects();
        $info = $pdf->add('<< /Title (chunk ' . $name . ') >>');
        $picture = $pdf->add(PdfObjects::stream('/Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceGray /BitsPerComponent 8', $shared));
        $pages = [];
        $tree = null;
        $reserved = [];

        foreach ($texts as $text) {
            $content = $pdf->add(PdfObjects::stream('', 'BT /F1 12 Tf 10 10 Td (' . $text . ') Tj ET'));
            $reserved[] = [$pdf->reserve(), $content];
        }

        $tree = $pdf->reserve();

        foreach ($reserved as [$page, $content]) {
            $pdf->set($page, sprintf(
                '<< /Type /Page /MediaBox [ 0 0 600 400 ] /Resources << /XObject << /X1 %s >> >> /Contents %s /Note (see 3 0 R \\) and <4 0 R>) /Hex <33203020 52> /Parent %s >>',
                PdfObjects::ref($picture),
                PdfObjects::ref($content),
                PdfObjects::ref($tree),
            ));
            $pages[] = PdfObjects::ref($page);
        }

        $pdf->set($tree, sprintf('<< /Type /Pages /Kids [ %s ] /Count %d >>', implode(' ', $pages), count($pages)));
        $catalog = $pdf->add('<< /Type /Catalog /Pages ' . PdfObjects::ref($tree) . ' >>');

        $path = sprintf('%s/%s.pdf', $this->directory, $name);
        file_put_contents($path, $pdf->toPdf('1.4', $catalog, $info, str_repeat('ab', 16)));

        return $path;
    }
}
