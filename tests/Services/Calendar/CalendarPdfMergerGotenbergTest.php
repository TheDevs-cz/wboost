<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Calendar;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Services\Calendar\CalendarPdfMerger;
use WBoost\Web\Services\Print\PdfPageBoxes;

/**
 * The export's FALLBACK merge — taken when {@see \WBoost\Web\Services\Print\PdfChunkMerger}
 * throws PdfStructureNotSupported — against the real Gotenberg (its
 * `/forms/pdf/merge` route since Gotenberg 9), followed by
 * {@see PdfPageBoxes}, exactly as CalendarPdfProducer::assemble() runs it.
 *
 * Self-contained: the chunks are printed by Gotenberg's Chromium from inline
 * HTML, so the `gotenberg` service is all it needs (CI: the calendar-render
 * job). Run with `vendor/bin/phpunit --group gotenberg --filter CalendarPdfMerger`.
 *
 * @covers \WBoost\Web\Services\Calendar\CalendarPdfMerger
 */
#[Group('gotenberg')]
final class CalendarPdfMergerGotenbergTest extends KernelTestCase
{
    private const float PT_PER_MM = 72 / 25.4;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/wboost-calendar-merge-test-' . bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    public function testChunksAreMergedInTheGivenOrderAndThePageBoxesCanBeSet(): void
    {
        $gs = (new ExecutableFinder())->find('gs');

        if ($gs === null) {
            self::markTestSkipped('Ghostscript is not installed.');
        }

        // File names sorting AGAINST the given order: the order of $paths must win.
        $paths = [
            $this->chunk('z-first.pdf', 100, 100, 2),
            $this->chunk('m-second.pdf', 200, 100, 1),
            $this->chunk('a-third.pdf', 100, 150, 1),
        ];

        $merged = self::getContainer()->get(CalendarPdfMerger::class)->merge($paths, $this->directory);

        self::assertSame($this->directory . '/merged.pdf', $merged);
        self::assertFileExists($merged);
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($merged, length: 8));

        // the inputs were consumed, the scratch directory is gone
        foreach ($paths as $path) {
            self::assertFileDoesNotExist($path);
        }
        self::assertDirectoryDoesNotExist($this->directory . '/merge-in');

        // 2 + 1 + 1 pages, in the order of $paths (told apart by their size)
        $sizes = [[100, 100], [100, 100], [200, 100], [100, 150]];
        $boxes = $this->boxes($gs, $merged);
        self::assertCount(4, $boxes);

        foreach ($sizes as $index => [$width, $height]) {
            $media = $boxes[$index + 1]['MediaBox'];
            self::assertEqualsWithDelta($width * self::PT_PER_MM, $media[2] - $media[0], 1.0, sprintf('page %d width', $index + 1));
            self::assertEqualsWithDelta($height * self::PT_PER_MM, $media[3] - $media[1], 1.0, sprintf('page %d height', $index + 1));
        }

        // The second half of the fallback: the boxes are set on what Gotenberg wrote.
        $width = 100 * self::PT_PER_MM;
        $height = 100 * self::PT_PER_MM;
        $pages = PdfPageBoxes::apply($merged, $width, $height, 5 * self::PT_PER_MM, 3 * self::PT_PER_MM, [
            'title' => 'Merge test',
            'creator' => 'WBoost',
            'producer' => 'WBoost (Chromium)',
            'createdAt' => new \DateTimeImmutable('2026-11-25 12:00:00'),
        ]);
        self::assertSame(4, $pages);

        foreach ($this->boxes($gs, $merged) as $number => $pageBoxes) {
            self::assertArrayHasKey('TrimBox', $pageBoxes, "page $number has a TrimBox");
            self::assertArrayHasKey('BleedBox', $pageBoxes, "page $number has a BleedBox");
        }
    }

    public function testABrokenChunkFailsWithGotenbergsReason(): void
    {
        $paths = [
            $this->chunk('first.pdf', 100, 100, 1),
            $this->directory . '/broken.pdf',
        ];
        file_put_contents($paths[1], "%PDF-1.4\nnot a PDF after all\n");

        try {
            self::getContainer()->get(CalendarPdfMerger::class)->merge($paths, $this->directory);
            self::fail('A broken chunk must not merge.');
        } catch (\RuntimeException $exception) {
            self::assertStringStartsWith('The calendar PDF chunks could not be merged: ', $exception->getMessage());
            self::assertStringContainsString('00001.pdf', $exception->getMessage(), 'Gotenberg names the input it could not read');
        }

        self::assertDirectoryDoesNotExist($this->directory . '/merge-in');
        self::assertFileDoesNotExist($this->directory . '/merged.pdf');
    }

    public function testASingleChunkIsOnlyRenamed(): void
    {
        $path = $this->chunk('only.pdf', 100, 100, 1);
        $bytes = (string) file_get_contents($path);

        $merged = self::getContainer()->get(CalendarPdfMerger::class)->merge([$path], $this->directory, 'single');

        self::assertSame($this->directory . '/single.pdf', $merged);
        self::assertSame($bytes, file_get_contents($merged));
    }

    /**
     * A Chromium-printed PDF of $pages pages of $width × $height mm.
     */
    private function chunk(string $name, int $width, int $height, int $pages): string
    {
        $body = '';

        for ($page = 1; $page <= $pages; $page++) {
            $body .= sprintf('<div style="%sheight:%dmm">%s page %d</div>', $page > 1 ? 'break-before:page;' : '', $height - 1, $name, $page);
        }

        $form = new FormDataPart([
            'files' => new DataPart('<!DOCTYPE html><html><body style="margin:0">' . $body . '</body></html>', 'index.html', 'text/html'),
            'paperWidth' => $width . 'mm',
            'paperHeight' => $height . 'mm',
            'marginTop' => '0',
            'marginBottom' => '0',
            'marginLeft' => '0',
            'marginRight' => '0',
        ]);

        $response = self::getContainer()->get('gotenberg.client')->request('POST', '/forms/chromium/convert/html', [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body' => $form->bodyToIterable(),
        ]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));

        $path = $this->directory . '/' . $name;
        file_put_contents($path, $response->getContent());

        return $path;
    }

    /**
     * @return array<int, array<string, list<float>>> page number → box name → [x0, y0, x1, y1]
     */
    private function boxes(string $gs, string $file): array
    {
        $process = new Process([$gs, '-q', '-dNODISPLAY', '-dBATCH', '-dNOPAUSE', '-dPDFINFO', $file]);
        $process->setTimeout(60);
        $process->mustRun();
        $boxes = [];

        foreach (explode("\n", $process->getOutput() . $process->getErrorOutput()) as $line) {
            if (preg_match('/^Page (\d+) /', $line, $page) !== 1) {
                continue;
            }

            preg_match_all('/(MediaBox|CropBox|BleedBox|TrimBox): \[([-\d. ]+)\]/', $line, $found, PREG_SET_ORDER);

            foreach ($found as $box) {
                $boxes[(int) $page[1]][$box[1]] = array_map(floatval(...), preg_split('/\s+/', trim($box[2])) ?: []);
            }
        }

        return $boxes;
    }
}
