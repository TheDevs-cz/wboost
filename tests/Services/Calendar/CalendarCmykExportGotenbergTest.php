<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Calendar;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Services\Calendar\CalendarModelBridge;
use WBoost\Web\Services\Calendar\CalendarPdfProducer;
use WBoost\Web\Services\Calendar\CalendarPdfRenderer;
use WBoost\Web\Services\Print\Cmyk\PdfRgbVerifier;
use WBoost\Web\Tests\Controller\Calendar\CalendarTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\Calendar\CalendarExportVariant;

/**
 * The REAL „Pro tisk (CMYK)“ export — node, Gotenberg's Chromium, the colour
 * pass, the PDF/X-4 frame — of a calendar that uses what carries colour: a
 * brand-coloured header, a background gradient with stop opacity, a
 * translucent kalendárium, a photo with rounded corners, border and shadow, a
 * QR picture, an event, a special page. Needs the `gotenberg` service; run
 * with `vendor/bin/phpunit --group gotenberg --filter CalendarCmyk`.
 *
 * What is asserted is INK, read back through Ghostscript's CMYK device
 * (`tiff32nc`, one byte per plate): the black text of the kalendárium has ink
 * on the K plate only, the header prints with exactly the palette's CMYK, the
 * crop marks are on all four plates — plus the frame (page boxes, PDF 1.6,
 * output intent, XMP), embedded fonts, live text and CMYK pictures.
 *
 * With `CALENDAR_PDF_DUMP=/path/prefix` the file is kept; with
 * `CALENDAR_CMYK_FIXTURE=1` the one-page Chromium chunk that
 * `tests/Services/Print/Cmyk/ChromiumChunkCmykTest` converts is rewritten.
 *
 * @covers \WBoost\Web\Services\Calendar\CalendarPdfProducer
 * @covers \WBoost\Web\Services\Print\Cmyk\PdfCmykConverter
 * @covers \WBoost\Web\Services\Print\Cmyk\CmykChunkConversion
 * @covers \WBoost\Web\Services\Print\Cmyk\PdfRgbVerifier
 * @covers \WBoost\Web\Services\Print\PdfChunkMerger
 */
#[Group('gotenberg')]
final class CalendarCmykExportGotenbergTest extends WebTestCase
{
    use CalendarTesting;

    public const string FIXTURE = __DIR__ . '/../../Fixtures/calendar/pdf/chromium-week-chunk.pdf';

    /** The palette's red (`TestDataFixture::PROJECT_1_COLOR_RED_ID`, #C8102E) with the CMYK a manual would give it. */
    public const array BRAND_CMYK = [0, 100, 80, 5];

    private const string WORKSPACE_URL = '/calendar/' . TestDataFixture::CALENDAR_2_ID;
    private const float PT_PER_MM = 72 / 25.4;
    /** Pixels per mm of the ink renders (254 dpi). */
    private const int PX = 10;
    /** The trim starts 13 mm inside the sheet: 5 mm bleed + 2 mm gap + 5 mm mark + 1 mm. */
    private const float INSET = 13.0;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/wboost-calendar-cmyk-test-' . bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    public function testTheCmykFilePrintsBlackTextInBlackOnlyAndBrandColoursInThePalettesInks(): void
    {
        $gs = (new ExecutableFinder())->find('gs');

        if ($gs === null) {
            self::markTestSkipped('Ghostscript is not installed.');
        }

        $pageKeys = $this->prepareCalendar();
        $calendar = self::calendar(TestDataFixture::CALENDAR_2_ID);

        if (getenv('CALENDAR_CMYK_FIXTURE') === '1') {
            $renderer = self::getContainer()->get(CalendarPdfRenderer::class);
            $chunk = $renderer->renderChunk($calendar, $renderer->plan($calendar, CalendarExportVariant::PrintCmyk), ['w05'], $this->directory, 'fixture');
            (new Filesystem())->copy($chunk, self::FIXTURE, true);
            unlink($chunk);
        }

        $progress = [];
        $file = self::getContainer()->get(CalendarPdfProducer::class)->produce(
            $calendar,
            CalendarExportVariant::PrintCmyk,
            $this->directory,
            static function (int $done, int $total) use (&$progress): void {
                $progress[] = [$done, $total];
            },
        );

        if (is_string(getenv('CALENDAR_PDF_DUMP'))) {
            copy($file, getenv('CALENDAR_PDF_DUMP') . '-print-cmyk.pdf');
        }

        $pages = count($pageKeys);
        self::assertSame([$pages, $pages], $progress[count($progress) - 1]);
        self::assertSame(['merged.pdf'], array_values(array_diff(scandir($this->directory) ?: [], ['.', '..'])), 'no chunk and no work file is left beside the result');

        // — the frame: PDF/X-4 —
        $bytes = (string) file_get_contents($file);
        self::assertStringStartsWith('%PDF-1.6', $bytes);
        self::assertStringContainsString('/OutputIntents [ 5 0 R ]', $bytes);
        self::assertStringContainsString('/S /GTS_PDFX /OutputConditionIdentifier (FOGRA39)', $bytes);
        self::assertStringContainsString('<pdfxid:GTS_PDFXVersion>PDF/X-4</pdfxid:GTS_PDFXVersion>', $bytes);
        self::assertStringContainsString('/Trapped /False', $bytes);
        unset($bytes);

        // — no RGB anywhere (the producer has checked; here once more, from the outside) —
        $report = PdfRgbVerifier::assertNoRgb($file);
        self::assertGreaterThanOrEqual($pages, $report['contentStreams']);
        self::assertGreaterThanOrEqual(3, $report['pictures']);

        // — pages, boxes, fonts, text: as in the RGB print file —
        $out = $this->command([$gs, '-q', '-dNODISPLAY', '-dBATCH', '-dNOPAUSE', '-dPDFINFO', $file]);
        self::assertSame(1, preg_match('/File has (\d+) pages?/', $out, $count), $out);
        self::assertSame($pages, (int) $count[1]);
        self::assertSame($pages, preg_match_all('/^Page \d+ .*MediaBox: \[([-\d. ]+)\].*$/m', $out, $media));
        self::assertSame($pages, preg_match_all('/TrimBox: \[([-\d. ]+)\]/', $out, $trim));
        self::assertSame($pages, preg_match_all('/BleedBox: \[([-\d. ]+)\]/', $out));
        $box = static fn (string $numbers): array => array_map(floatval(...), preg_split('/\s+/', trim($numbers)) ?: []);
        [$mx0, $my0, $mx1, $my1] = $box($media[1][0]);
        [$tx0, , $tx1, $ty1] = $box($trim[1][0]);
        self::assertEqualsWithDelta(336 * self::PT_PER_MM, $mx1 - $mx0, 0.01);
        self::assertEqualsWithDelta(171 * self::PT_PER_MM, $my1 - $my0, 0.01);
        self::assertEqualsWithDelta(310 * self::PT_PER_MM, $tx1 - $tx0, 0.01);
        self::assertEqualsWithDelta(self::INSET * self::PT_PER_MM, $my1 - $ty1, 0.01);
        self::assertGreaterThan(0, preg_match_all('/Descendants: \[\S+\s+\S+\s+Embedded/i', $out));
        self::assertSame(0, preg_match_all('/Not embedded/i', $out), 'every font is embedded');

        $text = $this->command([$gs, '-q', '-dBATCH', '-dNOPAUSE', '-sDEVICE=txtwrite', '-o', '-', $file]);
        self::assertStringContainsString('Pondělí', $text);
        self::assertStringContainsString('Hody na návsi', $text, 'text is still text');

        // — the inks of the week page with the photo —
        $number = (int) array_search('w05', $pageKeys, true) + 1;
        $ink = $this->inks($gs, $file, $number);

        // the header band is the brand red: exactly the palette's numbers, not the profile's guess
        self::assertSame(self::BRAND_CMYK, $ink->at(150.0, 20.0), 'the header in the brand colour');
        self::assertSame(self::BRAND_CMYK, $ink->at(-3.0, 10.0), '…into the bleed');

        // the kalendárium (x 197 … 310 mm): every dark pixel — the black day numbers (K 100), the names
        // in the default text colour #2B2830 (K 84), the day words in #6E6977 (K 58) — has ink on the
        // K plate ONLY. A profile conversion would put 91 / 79 / 62 % under black and 83 / 82 / 52 %
        // under #2B2830.
        [$dark, $rich, $solid] = $ink->darkPixels(200.0, 30.0, 308.0, 136.0);
        self::assertGreaterThan(3000, $dark, 'the texts are there');
        self::assertGreaterThan(1500, $solid, 'the day numbers are K 100');
        self::assertSame(0, $rich, 'no dark pixel of the text carries cyan, magenta or yellow');

        // the photo is a four-colour picture
        [$c, $m, $y, $k] = $ink->at(60.0, 80.0);
        self::assertGreaterThan(60, $c + $m + $y, 'the photo prints in colour');
        self::assertLessThan(100, $k);

        // crop marks: on every plate (registration), in the band outside the bleed; the band is otherwise paper
        self::assertSame([100, 100, 100, 100], $ink->darkest(-12.0, -0.5, -7.5, 0.5), 'the mark left of the top-left trim corner');
        self::assertSame([100, 100, 100, 100], $ink->darkest(-0.5, -12.0, 0.5, -7.5), 'the mark above it');
        self::assertSame([100, 100, 100, 100], $ink->darkest(309.5, 152.5, 310.5, 157.0), 'below the bottom-right corner');
        self::assertSame([0, 0, 0, 0], $ink->at(-9.0, -9.0), 'paper in the corner of the sheet');
        self::assertSame([0, 0, 0, 0], $ink->at(100.0, -6.5), 'no ink between the bleed and the sheet edge');

        // — pictures: CMYK (the photo, its pixels kept) or grey (the QR code, the shadow) —
        $pictures = self::pictures($file);
        self::assertContains([600, 400, 'DeviceCMYK', 'DCTDecode'], $pictures, 'the photo: same pixels, CMYK JPEG');
        self::assertContains([116, 116, 'DeviceGray', 'FlateDecode'], $pictures, 'the QR code: black ink only');

        foreach ($pictures as $picture) {
            self::assertContains($picture[2], ['DeviceCMYK', 'DeviceGray'], 'every picture is CMYK or grey');
        }
    }

    /**
     * Through the web, as a designer would. The header takes the palette's
     * red, whose CMYK the test gives it as a manual would.
     *
     * @return list<string> the page keys
     */
    private function prepareCalendar(): array
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE project_color SET cmyk = :cmyk WHERE id = :id',
            ['cmyk' => json_encode(array_map(strval(...), self::BRAND_CMYK)), 'id' => TestDataFixture::PROJECT_1_COLOR_RED_ID],
        );

        $photo = self::upload($browser, self::WORKSPACE_URL . '/asset', $token, 'photo', self::noisyJpeg(600, 400), 'naves.jpg');
        self::assertResponseIsSuccessful();
        $qr = self::upload($browser, self::WORKSPACE_URL . '/asset', $token, 'qr', self::qrPng(116), 'qr.png');
        self::assertResponseIsSuccessful((string) $browser->getResponse()->getContent());

        // the materialised layout, with everything that carries colour switched on
        $layout = self::getContainer()->get(CalendarModelBridge::class)->run(self::calendar(TestDataFixture::CALENDAR_2_ID))->design->layout;
        self::assertInstanceOf(\stdClass::class, $layout);
        $styles = self::node($layout, 'styles');
        $headFill = self::node(self::node($styles, 'head'), 'bg');
        $headFill->colorOn = true;
        $headFill->color = '#C8102E';
        $headFill->colorOpacity = 100;
        self::node($headFill, 'gradient')->on = false;
        self::node($styles, 'bg')->gradient = ['on' => true, 'type' => 'linear', 'angle' => 45, 'stops' => [['color' => '#FFE9B0', 'opacity' => 100, 'pos' => 0], ['color' => '#8FC7FF', 'opacity' => 60, 'pos' => 100]]];
        self::node($styles, 'cal')->bg = ['color' => '#FFFFFF', 'opacity' => 100];
        $photoStyle = self::node($styles, 'photo');
        $photoStyle->radius = 6;
        $photoStyle->border = ['on' => true, 'width' => 1, 'color' => '#FFFFFF'];
        $photoStyle->shadow = ['on' => true, 'blur' => 4, 'opacity' => 45, 'dx' => 2, 'dy' => 2.5, 'color' => '#000000'];
        $styles->lines = ['on' => true, 'width' => 0.8, 'style' => 'dashed', 'color' => '#004E7C'];
        // the day numbers in pure black; the names and the day words keep the calendar's default
        // text colours (#2B2830, #6E6977 — tinted greys)
        self::node($styles, 'num')->color = '#000000';
        self::node($styles, 'qr')->asset = $qr['id'];
        $boxes = self::node($layout, 'boxes');
        $boxes->photo = ['x' => 8, 'y' => 34, 'w' => 180, 'h' => 100];
        $boxes->qr = ['x' => 170, 'y' => 110, 'w' => 20, 'h' => 20];

        self::save($browser, self::WORKSPACE_URL . '/save', [
            'design' => [
                'layout' => $layout,
                'features' => ['captions' => true, 'weekNumbers' => true, 'moon' => true, 'timeChange' => false, 'seasons' => false, 'waste' => false, 'events' => true, 'shifts' => false, 'specialPages' => true, 'qr' => true],
            ],
            'content' => [
                ['c' => 'pagePhotos', 'id' => 'w05', 'v' => ['asset' => $photo['id'], 'zoom' => 1, 'px' => 0.5, 'py' => 0.5], 'label' => '5. týden'],
                ['c' => 'events', 'id' => 'evHody', 'v' => self::event('Hody na návsi', '2027-02-03'), 'label' => '3. 2.'],
                ['c' => 'specialPages', 'id' => 'intro', 'v' => ['kind' => 'intro', 'on' => true, 'layout' => 0, 'position' => 'front', 'nextYear' => false, 'name' => '', 'asset' => null, 'text' => 'Vážení spoluobčané.', 'fileName' => null, 'pos' => 0], 'label' => 'Úvodní slovo'],
            ],
        ], $token);
        self::assertResponseIsSuccessful((string) $browser->getResponse()->getContent());

        $model = self::getContainer()->get(CalendarModelBridge::class)->run(self::calendar(TestDataFixture::CALENDAR_2_ID), pages: true);

        return array_column($model->pages ?? [], 'key');
    }

    private static function node(\stdClass $of, string $key): \stdClass
    {
        $node = $of->{$key} ?? null;
        self::assertInstanceOf(\stdClass::class, $node, $key);

        return $node;
    }

    /**
     * One page rendered on Ghostscript's CMYK device: a byte per plate and
     * pixel, no colour management on the way — the numbers in the file.
     */
    private function inks(string $gs, string $file, int $page): InkPlates
    {
        $tiff = $this->directory . '/inks.tif';
        $this->command([$gs, '-q', '-dBATCH', '-dNOPAUSE', '-sDEVICE=tiff32nc', '-sCompression=none', '-r' . (self::PX * 25.4), '-dFirstPage=' . $page, '-dLastPage=' . $page, '-o', $tiff, $file]);
        $image = new \Imagick($tiff);
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        // four bytes per pixel (255 = full ink) as ONE string: a PHP array of 23 million integers would not fit
        $image->setImageDepth(8);
        $image->writeImage('cmyk:' . $this->directory . '/inks.raw');
        $image->clear();
        $pixels = (string) file_get_contents($this->directory . '/inks.raw');
        unlink($this->directory . '/inks.raw');
        unlink($tiff);
        self::assertSame(4 * $width * $height, strlen($pixels));

        return new InkPlates($pixels, $width, $height, self::PX, self::INSET);
    }

    /**
     * The image XObjects of the file: [width, height, colour space, filter].
     *
     * @return list<array{int, int, string, string}>
     */
    private static function pictures(string $file): array
    {
        $bytes = (string) file_get_contents($file);
        preg_match_all('~<<(?:(?!>>|stream).)*?/Subtype\s*/Image(?:(?!>>|stream).)*?>>~s', $bytes, $dictionaries);
        $pictures = [];

        foreach ($dictionaries[0] as $dictionary) {
            if (preg_match('~/Width\s+(\d+)~', $dictionary, $width) !== 1 || preg_match('~/Height\s+(\d+)~', $dictionary, $height) !== 1) {
                continue;
            }

            $pictures[] = [
                (int) $width[1],
                (int) $height[1],
                preg_match('~/ColorSpace\s*/(\w+)~', $dictionary, $space) === 1 ? $space[1] : '?',
                preg_match('~/Filter\s*/(\w+)~', $dictionary, $filter) === 1 ? $filter[1] : '',
            ];
        }

        return $pictures;
    }

    /**
     * @param list<string> $command
     */
    private function command(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout(180);
        $process->mustRun();

        return $process->getOutput() . $process->getErrorOutput();
    }

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

    /** A black-and-white checker that stands in for a QR code. */
    private static function qrPng(int $size): string
    {
        $image = new \Imagick();
        $image->newPseudoImage($size, $size, 'pattern:checkerboard');
        $image->thresholdImage(0.5 * \Imagick::getQuantum());
        $image->setImageType(\Imagick::IMGTYPE_TRUECOLOR);
        $image->setImageFormat('png24');
        $bytes = $image->getImageBlob();
        $image->clear();

        return $bytes;
    }
}
