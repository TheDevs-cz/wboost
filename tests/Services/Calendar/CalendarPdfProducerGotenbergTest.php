<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Calendar;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Services\Calendar\CalendarPdfProducer;
use WBoost\Web\Tests\Controller\Calendar\CalendarTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\Calendar\CalendarExportVariant;

/**
 * The REAL export — node, Gotenberg's Chromium, its qpdf merge, the page
 * boxes — of a calendar with a photo on one week and a special page. Needs
 * the `gotenberg` service; run with
 * `vendor/bin/phpunit --group gotenberg --filter CalendarPdfProducer`.
 *
 * The file is read back with Ghostscript (`-dPDFINFO`: page count, the boxes
 * of every page, the fonts and whether they are embedded; `txtwrite`: the
 * text) and its image objects are read from the file itself — so what is
 * asserted is what a print shop opens: vector pages of the exact size, live
 * text, embedded fonts and the photo with the pixels it was uploaded with.
 *
 * @covers \WBoost\Web\Services\Calendar\CalendarPdfProducer
 * @covers \WBoost\Web\Services\Calendar\CalendarPdfRenderer
 * @covers \WBoost\Web\Services\Calendar\CalendarPdfMerger
 * @covers \WBoost\Web\Services\Calendar\CalendarRenderAssets
 * @covers \WBoost\Web\Services\Print\PdfPageBoxes
 */
#[Group('gotenberg')]
final class CalendarPdfProducerGotenbergTest extends WebTestCase
{
    use CalendarTesting;

    private const string WORKSPACE_URL = '/calendar/' . TestDataFixture::CALENDAR_2_ID;
    private const float PT_PER_MM = 72 / 25.4;
    private const int PHOTO_WIDTH = 2400;
    private const int PHOTO_HEIGHT = 1600;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/wboost-calendar-pdf-test-' . bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    public function testPrintFileIsVectorHasTheExactPageBoxesAndTheUploadedPhoto(): void
    {
        $gs = (new ExecutableFinder())->find('gs');

        if ($gs === null) {
            self::markTestSkipped('Ghostscript is not installed.');
        }

        $pageKeys = $this->prepareCalendar();
        $progress = [];

        $file = self::getContainer()->get(CalendarPdfProducer::class)->produce(
            self::calendar(TestDataFixture::CALENDAR_2_ID),
            CalendarExportVariant::Print,
            $this->directory,
            static function (int $done, int $total) use (&$progress): void {
                $progress[] = [$done, $total];
            },
        );

        if (is_string(getenv('CALENDAR_PDF_DUMP'))) {
            copy($file, getenv('CALENDAR_PDF_DUMP') . '-print.pdf');
        }

        // cover, the special page, the week pages, back — and the progress walked through them
        $pages = count($pageKeys);
        self::assertGreaterThanOrEqual(55, $pages);
        self::assertSame(['cover', 'sp:intro'], array_slice($pageKeys, 0, 2));
        self::assertSame('back', $pageKeys[$pages - 1]);
        self::assertSame([0, $pages], $progress[0]);
        self::assertSame([$pages, $pages], $progress[count($progress) - 1]);
        self::assertGreaterThan(5, count($progress), 'rendered in chunks');

        $info = $this->info($gs, $file);
        self::assertSame($pages, $info['pages']);
        self::assertCount($pages, $info['boxes']);

        // 310 × 145 mm trim, 5 mm bleed, crop marks: the sheet is trim + 2 × (5 + 8) mm.
        foreach ($info['boxes'] as $number => $boxes) {
            self::assertEqualsWithDelta(336 * self::PT_PER_MM, $boxes['MediaBox'][2] - $boxes['MediaBox'][0], 0.01, "page $number media width");
            self::assertEqualsWithDelta(171 * self::PT_PER_MM, $boxes['MediaBox'][3] - $boxes['MediaBox'][1], 0.01, "page $number media height");
            self::assertSame($boxes['MediaBox'], $boxes['CropBox']);
            self::assertEqualsWithDelta(310 * self::PT_PER_MM, $boxes['TrimBox'][2] - $boxes['TrimBox'][0], 0.01, "page $number trim width");
            self::assertEqualsWithDelta(145 * self::PT_PER_MM, $boxes['TrimBox'][3] - $boxes['TrimBox'][1], 0.01, "page $number trim height");
            self::assertEqualsWithDelta(13 * self::PT_PER_MM, $boxes['TrimBox'][0] - $boxes['MediaBox'][0], 0.01, "page $number trim inset");
            self::assertEqualsWithDelta(13 * self::PT_PER_MM, $boxes['MediaBox'][3] - $boxes['TrimBox'][3], 0.01, "page $number trim inset from the top");
            self::assertEqualsWithDelta(320 * self::PT_PER_MM, $boxes['BleedBox'][2] - $boxes['BleedBox'][0], 0.01, "page $number bleed width");
        }

        // Text is text: fonts are in the file, every one embedded, and the words can be read back.
        self::assertNotSame([], $info['fonts']);
        self::assertSame([], $info['notEmbedded'], 'every font is embedded');
        $text = $this->text($gs, $file);
        self::assertStringContainsString('Pondělí', $text);
        self::assertStringContainsString('Neděle', $text);
        self::assertStringContainsString('2027', $text);
        self::assertStringContainsString('Hody na návsi', $text, 'the event');

        // The ONLY raster in the file is the photo — at the pixels it was uploaded with, its JPEG data untouched.
        $images = self::images($file);
        self::assertSame([[self::PHOTO_WIDTH, self::PHOTO_HEIGHT, 'DCTDecode']], $images);
        // The binding holes are punched at the bindery — the print file must not paint them.
        // Week 5's page: the centre of the first hole is the header's own colour.
        $page = (int) array_search('w05', $pageKeys, true) + 1;
        [$hole, $between] = $this->holeProbe($gs, $file, $page, 13.0);
        self::assertSame($between, $hole, 'no hole squares in the print file');

        // the document title (UTF-16BE in the Info dictionary of the appended update)
        self::assertStringContainsString('/Title <FEFF' . strtoupper(bin2hex(mb_convert_encoding('Obecní kalendář', 'UTF-16BE', 'UTF-8'))) . '>', (string) file_get_contents($file));
    }

    public function testApprovalFileIsTrimmedAndCarriesThePreviewOfThePhoto(): void
    {
        $gs = (new ExecutableFinder())->find('gs');

        if ($gs === null) {
            self::markTestSkipped('Ghostscript is not installed.');
        }

        $pageKeys = $this->prepareCalendar();

        $file = self::getContainer()->get(CalendarPdfProducer::class)->produce(
            self::calendar(TestDataFixture::CALENDAR_2_ID),
            CalendarExportVariant::Approval,
            $this->directory,
            static function (): void {
            },
        );

        if (is_string(getenv('CALENDAR_PDF_DUMP'))) {
            copy($file, getenv('CALENDAR_PDF_DUMP') . '-approval.pdf');
        }

        $info = $this->info($gs, $file);
        self::assertSame(count($pageKeys), $info['pages']);

        foreach ($info['boxes'] as $number => $boxes) {
            self::assertEqualsWithDelta(310 * self::PT_PER_MM, $boxes['MediaBox'][2] - $boxes['MediaBox'][0], 0.01, "page $number width");
            self::assertEqualsWithDelta(145 * self::PT_PER_MM, $boxes['MediaBox'][3] - $boxes['MediaBox'][1], 0.01, "page $number height");
            self::assertSame($boxes['MediaBox'], $boxes['TrimBox'], 'no bleed, no marks: the page is the trim');
        }

        self::assertSame([], $info['notEmbedded']);
        self::assertStringContainsString('Pondělí', $this->text($gs, $file));
        // The approval file shows the finished product: the row of holes is drawn.
        [$hole, $between] = $this->holeProbe($gs, $file, (int) array_search('w05', $pageKeys, true) + 1, 0.0);
        self::assertNotSame($between, $hole, 'the hole squares are in the approval file');

        // the ≤ 1600 px preview, as a JPEG (Chromium would store a WebP losslessly — megabytes)
        self::assertSame([[1600, 1067, 'DCTDecode']], self::images($file));
    }

    /**
     * Through the web, as a designer would: a photo on week 5, an event, the
     * intro page switched on.
     *
     * @return list<string> the page keys
     */
    private function prepareCalendar(): array
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $bootstrap = self::bootstrap($browser, self::WORKSPACE_URL);
        $token = self::csrf($bootstrap);

        $photo = self::upload($browser, self::WORKSPACE_URL . '/asset', $token, 'photo', self::noisyJpeg(self::PHOTO_WIDTH, self::PHOTO_HEIGHT), 'naves.jpg');
        self::assertResponseIsSuccessful();
        self::assertIsString($photo['previewUrl'], 'a photo wider than 1600 px gets a preview');

        self::save($browser, self::WORKSPACE_URL . '/save', [
            'design' => ['features' => ['captions' => true, 'weekNumbers' => true, 'moon' => true, 'timeChange' => false, 'seasons' => false, 'waste' => false, 'events' => true, 'shifts' => false, 'specialPages' => true, 'qr' => false]],
            'content' => [
                ['c' => 'pagePhotos', 'id' => 'w05', 'v' => ['asset' => $photo['id'], 'zoom' => 1.4, 'px' => 0.3, 'py' => 0.6], 'label' => '5. týden'],
                ['c' => 'events', 'id' => 'evHody', 'v' => self::event('Hody na návsi', '2027-02-03'), 'label' => '3. 2.'],
                ['c' => 'specialPages', 'id' => 'intro', 'v' => ['kind' => 'intro', 'on' => true, 'layout' => 0, 'position' => 'front', 'nextYear' => false, 'name' => '', 'asset' => null, 'text' => 'Vážení spoluobčané.', 'fileName' => null, 'pos' => 0], 'label' => 'Úvodní slovo'],
            ],
        ], $token);
        self::assertResponseIsSuccessful((string) $browser->getResponse()->getContent());

        $model = self::getContainer()->get(\WBoost\Web\Services\Calendar\CalendarModelBridge::class)->run(self::calendar(TestDataFixture::CALENDAR_2_ID), pages: true);

        return array_column($model->pages ?? [], 'key');
    }

    /**
     * @return array{pages: int, boxes: array<int, array<string, array{float, float, float, float}>>, fonts: list<string>, notEmbedded: list<string>}
     */
    private function info(string $gs, string $file): array
    {
        $process = new Process([$gs, '-q', '-dNODISPLAY', '-dBATCH', '-dNOPAUSE', '-dPDFINFO', $file]);
        $process->setTimeout(120);
        $process->mustRun();
        $out = $process->getOutput() . $process->getErrorOutput();

        self::assertSame(1, preg_match('/File has (\d+) pages?/', $out, $count), $out);
        $boxes = [];

        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^Page (\d+) /', $line, $page) !== 1) {
                continue;
            }

            preg_match_all('/(MediaBox|CropBox|BleedBox|TrimBox): \[([-\d. ]+)\]/', $line, $found, PREG_SET_ORDER);

            foreach ($found as $box) {
                $values = array_map(floatval(...), preg_split('/\s+/', trim($box[2])) ?: []);
                self::assertCount(4, $values);
                $boxes[(int) $page[1]][$box[1]] = [round($values[0], 2), round($values[1], 2), round($values[2], 2), round($values[3], 2)];
            }
        }

        preg_match_all('/Descendants: \[(\S+)\s+\S+\s+(Embedded|Not embedded)/i', $out, $fonts, PREG_SET_ORDER);
        $names = [];
        $notEmbedded = [];

        foreach ($fonts as $font) {
            $names[$font[1]] = true;

            if (strcasecmp($font[2], 'Embedded') !== 0) {
                $notEmbedded[$font[1]] = true;
            }
        }

        return [
            'pages' => (int) $count[1],
            'boxes' => $boxes,
            'fonts' => array_keys($names),
            'notEmbedded' => array_keys($notEmbedded),
        ];
    }

    /**
     * The colour in the middle of the first binding hole and in the gap
     * right of it (default page: 38 holes of 4.3 mm, pitch 8, 4 mm from the
     * top, the row centred → the first hole starts at x = 4.85 mm).
     *
     * @param float $inset distance of the trim from the sheet's edge, mm
     *
     * @return array{string, string} two `r,g,b` strings
     */
    private function holeProbe(string $gs, string $file, int $page, float $inset): array
    {
        $png = $this->directory . '/probe.png';
        $dpi = 254; // 10 px per mm
        (new Process([$gs, '-q', '-dBATCH', '-dNOPAUSE', '-sDEVICE=png16m', '-r' . $dpi, '-dFirstPage=' . $page, '-dLastPage=' . $page, '-o', $png, $file]))->setTimeout(120)->mustRun();

        $image = new \Imagick($png);
        $at = static function (float $xMm, float $yMm) use ($image, $inset): string {
            $color = $image->getImagePixelColor((int) round(($inset + $xMm) * 10), (int) round(($inset + $yMm) * 10))->getColor();

            return sprintf('%d,%d,%d', $color['r'], $color['g'], $color['b']);
        };
        $probe = [$at(4.85 + 2.15, 4 + 2.15), $at(4.85 + 4.3 + 1.85, 4 + 2.15)];
        $image->clear();

        return $probe;
    }

    private function text(string $gs, string $file): string
    {
        $process = new Process([$gs, '-q', '-dBATCH', '-dNOPAUSE', '-sDEVICE=txtwrite', '-o', '-', $file]);
        $process->setTimeout(120);
        $process->mustRun();

        return $process->getOutput();
    }

    /**
     * The image XObjects of the file: [width, height, filter].
     *
     * @return list<array{int, int, string}>
     */
    private static function images(string $file): array
    {
        $bytes = (string) file_get_contents($file);
        preg_match_all('~<<(?:(?!>>|stream).)*?/Subtype\s*/Image(?:(?!>>|stream).)*?>>~s', $bytes, $dictionaries);
        $images = [];

        foreach ($dictionaries[0] as $dictionary) {
            if (preg_match('~/Width\s+(\d+)~', $dictionary, $width) !== 1 || preg_match('~/Height\s+(\d+)~', $dictionary, $height) !== 1) {
                continue;
            }

            // a soft mask is not a picture of its own
            if (preg_match('~/ColorSpace\s*/DeviceGray~', $dictionary) === 1 && (int) $width[1] <= 2) {
                continue;
            }

            $images[] = [(int) $width[1], (int) $height[1], preg_match('~/Filter\s*/?\[?\s*/(\w+)~', $dictionary, $filter) === 1 ? $filter[1] : ''];
        }

        return $images;
    }

    /** A JPEG with fine detail, so the upload is not a few-kB flat colour. */
    private static function noisyJpeg(int $width, int $height): string
    {
        $image = new \Imagick();
        $image->newPseudoImage($width, $height, 'plasma:fractal');
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(85);
        $bytes = $image->getImageBlob();
        $image->clear();

        return $bytes;
    }
}
