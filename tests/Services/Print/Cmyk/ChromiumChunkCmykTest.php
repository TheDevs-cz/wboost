<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Print\Cmyk;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\CmykConversionFailed;
use WBoost\Web\Services\Print\Cmyk\CropMarkLines;
use WBoost\Web\Services\Print\Cmyk\PdfCmykConverter;
use WBoost\Web\Services\Print\Cmyk\PdfDict;
use WBoost\Web\Services\Print\Cmyk\PdfReader;
use WBoost\Web\Services\Print\Cmyk\PdfRgbVerifier;
use WBoost\Web\Services\Print\Cmyk\PdfSyntax;
use WBoost\Web\Value\CmykColor;

/**
 * The colour pass on what Chromium REALLY prints: one week page of a
 * calendar (`tests/Fixtures/calendar/pdf/chromium-week-chunk.pdf`, written by
 * `CalendarCmykExportGotenbergTest` with `CALENDAR_CMYK_FIXTURE=1`) — a
 * brand-red header, a background gradient with stop opacity (shading
 * patterns + luminosity soft masks in DeviceRGB groups), a JPEG photo tagged
 * with an ICC profile, clipped round, with a border and a filter-rasterised
 * shadow (RGB picture + soft mask), a QR picture, embedded TrueType subsets,
 * dashed lines. If a new Chromium writes something else, this is where it
 * shows first — regenerate the fixture and read the failure.
 *
 * @covers \WBoost\Web\Services\Print\Cmyk\PdfCmykConverter
 * @covers \WBoost\Web\Services\Print\Cmyk\CmykChunkConversion
 * @covers \WBoost\Web\Services\Print\Cmyk\PdfRgbVerifier
 */
final class ChromiumChunkCmykTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../../Fixtures/calendar/pdf/chromium-week-chunk.pdf';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pdf-chromium-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testTheFixtureIsRgbAsChromiumWritesIt(): void
    {
        $this->expectException(CmykConversionFailed::class);
        $this->expectExceptionMessage('RGB is left');

        PdfRgbVerifier::assertNoRgb(self::FIXTURE);
    }

    public function testAChromiumPageBecomesCmykAndNothingElseChanges(): void
    {
        $converter = new PdfCmykConverter(CmykColorRulesTest::icc());
        $pass = $converter->pass(['#c8102e' => new CmykColor(0, 100, 80, 5)], $this->directory);
        $target = $this->directory . '/cmyk.pdf';

        $converter->convert(self::FIXTURE, $target, $pass, CropMarkLines::around(952.44, 484.72, 36.85, 19.84, 14.17, 0.25));

        $report = PdfRgbVerifier::assertNoRgb($target);
        self::assertGreaterThanOrEqual(4, $report['contentStreams'], 'the page, its marks and the forms of the soft masks');
        self::assertGreaterThanOrEqual(3, $report['pictures']);

        $counts = $pass->rules->counts();
        self::assertSame(1, $counts['brand'], 'the header red is the palette\'s');
        self::assertGreaterThanOrEqual(2, $counts['neutral'], 'white, black');
        self::assertGreaterThanOrEqual(2, $counts['text'], 'the default text colours #2B2830 and #6E6977');
        self::assertGreaterThanOrEqual(3, $counts['icc']);
        $pass->close();

        $source = self::describe(new PdfReader(self::FIXTURE));
        $converted = self::describe(new PdfReader($target));

        // text: the same embedded font programs, byte for byte
        self::assertNotSame([], $source['fontPrograms']);
        self::assertSame($source['fontPrograms'], $converted['fontPrograms']);

        // every operator that is not a colour is there exactly as often as before: the glyph runs,
        // the paths, the clips, the pictures, the graphics states
        $colour = ['rg' => 0, 'RG' => 0, 'k' => 0, 'K' => 0];
        self::assertGreaterThan(100, $source['operators']['Tj'] ?? 0);
        self::assertSame(array_diff_key($source['operators'], $colour), array_diff_key($converted['operators'], $colour));

        // colour operators: every rg / RG has become k / K (and text adds its own black)
        self::assertGreaterThan(20, $source['operators']['rg'] ?? 0);
        self::assertArrayNotHasKey('rg', $converted['operators']);
        self::assertArrayNotHasKey('RG', $converted['operators']);
        self::assertSame($source['operators']['RG'] ?? 0, $converted['operators']['K'] ?? 0);
        self::assertGreaterThan($source['operators']['rg'] ?? 0, $converted['operators']['k'] ?? 0);
        self::assertStringContainsString("0 1 0.8 0.05 k\n", $converted['content'], 'the brand header');
        self::assertStringContainsString("0 0 0 0.836 k\nBT\n", $converted['content'], 'text in the default colour: black ink only');
        self::assertStringContainsString("0 0 0 1 k\nBT\n", $converted['content'], 'the day numbers: K 100');

        // pictures: same count and pixels; the photo CMYK, the shadow and the QR code grey
        self::assertSame(array_column($source['pictures'], 'size'), array_column($converted['pictures'], 'size'));
        self::assertContains('ICCBased', array_column($source['pictures'], 'space'));
        self::assertContains('DeviceRGB', array_column($source['pictures'], 'space'));
        self::assertEqualsCanonicalizing(['DeviceCMYK', 'DeviceGray'], array_values(array_unique(array_column($converted['pictures'], 'space'))));

        // gradients and their opacity masks
        self::assertContains('DeviceRGB', $source['shadings']);
        self::assertEqualsCanonicalizing(['DeviceCMYK', 'DeviceGray'], array_values(array_unique($converted['shadings'])));
        self::assertContains('DeviceRGB', $source['groups']);
        self::assertSame(['DeviceGray'], array_values(array_unique($converted['groups'])), 'the luminosity masks blend in grey');
    }

    /**
     * @return array{operators: array<string, int>, content: string, fontPrograms: list<string>, pictures: list<array{size: string, space: string}>, shadings: list<string>, groups: list<string>}
     */
    private static function describe(PdfReader $reader): array
    {
        $facts = ['operators' => [], 'content' => '', 'fontPrograms' => [], 'pictures' => [], 'shadings' => [], 'groups' => []];
        $space = static function (mixed $value) use ($reader): string {
            $value = $reader->resolve($value);

            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            return $value instanceof \WBoost\Web\Services\Print\Cmyk\PdfName ? $value->value : '?';
        };

        foreach ($reader->numbers() as $number) {
            $value = $reader->value($number);

            if (!$value instanceof PdfDict) {
                continue;
            }

            $shading = $reader->resolve($value->get('Shading'));

            if ($shading instanceof PdfDict) {
                $facts['shadings'][] = $space($shading->get('ColorSpace'));
            }

            $group = $reader->resolve($value->get('Group'));

            if ($group instanceof PdfDict && $group->has('CS')) {
                $facts['groups'][] = $space($group->get('CS'));
            }

            if (!$reader->isStream($number)) {
                continue;
            }

            if ($value->has('Length1')) {
                $facts['fontPrograms'][] = md5($reader->streamBytes($number));
            } elseif ($value->name('Subtype') === 'Image') {
                $facts['pictures'][] = ['size' => $reader->resolve($value->get('Width')) . 'x' . $reader->resolve($value->get('Height')), 'space' => $space($value->get('ColorSpace'))];
            } elseif (!$value->has('N') && !$value->has('FunctionType')) {
                // Skia leaves short streams uncompressed
                $data = $value->name('Filter') === 'FlateDecode' ? (string) @gzuncompress($reader->streamBytes($number)) : $reader->streamBytes($number);

                if (preg_match('/\b(re|BT|Do|cm)\b/', $data) !== 1) {
                    // a ToUnicode map
                    continue;
                }

                $facts['content'] .= $data;
                PdfSyntax::operations($data, static function (string $operator) use (&$facts): null {
                    $facts['operators'][$operator] = ($facts['operators'][$operator] ?? 0) + 1;

                    return null;
                });
            }
        }

        ksort($facts['operators']);
        sort($facts['fontPrograms']);
        sort($facts['pictures']);

        return $facts;
    }
}
