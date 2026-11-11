<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Print\Cmyk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Exceptions\CmykConversionFailed;
use WBoost\Web\Services\Print\Cmyk\CropMarkLines;
use WBoost\Web\Services\Print\Cmyk\PdfCmykConverter;
use WBoost\Web\Services\Print\Cmyk\PdfDict;
use WBoost\Web\Services\Print\Cmyk\PdfName;
use WBoost\Web\Services\Print\Cmyk\PdfReader;
use WBoost\Web\Services\Print\Cmyk\PdfRef;
use WBoost\Web\Services\Print\Cmyk\PdfRgbVerifier;
use WBoost\Web\Services\Print\Cmyk\PdfSyntax;
use WBoost\Web\Services\Print\Cmyk\PdfWriter;
use WBoost\Web\Value\CmykColor;

/**
 * The colour pass on hand-built PDFs that hold, one by one, every construct
 * it has a rule for — and the ones it must refuse. (What Chromium really
 * writes is covered by {@see ChromiumChunkCmykTest}.)
 *
 * @covers \WBoost\Web\Services\Print\Cmyk\PdfCmykConverter
 * @covers \WBoost\Web\Services\Print\Cmyk\CmykChunkConversion
 * @covers \WBoost\Web\Services\Print\Cmyk\CmykPass
 * @covers \WBoost\Web\Services\Print\Cmyk\PdfReader
 * @covers \WBoost\Web\Services\Print\Cmyk\PdfWriter
 */
final class PdfCmykConverterTest extends TestCase
{
    private const string BRAND = '.3569 .2784 .6588';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pdf-cmyk-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testOperatorsOfThePageContentAndCropMarks(): void
    {
        $out = $this->convert(
            $this->pdf("1 0 0 -1 0 200 cm\nq\n0 0 0 RG 0 0 0 rg\nBT /F1 12 Tf (Text) Tj ET\n" . self::BRAND . " rg\n10 10 50 50 re\nf\nQ", '<< /Font << /F1 10 0 R >> >>', [
                10 => '<< /Type /Font /Subtype /TrueType /BaseFont /Demo /FontDescriptor 11 0 R >>',
                11 => '<< /Type /FontDescriptor /FontName /Demo /FontFile2 12 0 R >>',
                12 => ['<< /Length1 4 >>', 'FONT'],
            ]),
            CropMarkLines::around(300.0, 200.0, 30.0, 20.0, 8.0, 0.25),
        );

        $page = $this->dict($out, 3);
        $contents = $page->get('Contents');
        self::assertIsArray($contents);
        self::assertCount(3, $contents, 'the page content is fenced in q … Q and the marks follow');
        self::assertInstanceOf(PdfRef::class, $contents[0]);
        self::assertInstanceOf(PdfRef::class, $contents[1]);
        self::assertInstanceOf(PdfRef::class, $contents[2]);
        self::assertSame("q\n", $this->data($out, $contents[0]->number));

        $content = $this->data($out, $contents[1]->number);
        self::assertStringContainsString("0 0 0 1 K\n0 0 0 1 k\nBT\n", $content, 'black text: black ink only');
        self::assertStringContainsString("0.75 0.8 0 0.05 k\n10 10 50 50 re\nf\n", $content, 'the brand colour: the palette\'s CMYK');
        self::assertStringNotContainsString('rg', $content);

        // eight marks on the trim lines, 20 pt off the trim corner, 8 pt long, measured from the TOP-left corner
        $marks = $this->data($out, $contents[2]->number);
        self::assertStringStartsWith("Q\nq\n/WbRegistration CS 1 SCN\n0.25 w 0 J [] 0 d\n", $marks);
        self::assertSame(8, substr_count($marks, ' l S'));
        self::assertStringContainsString("2 170 m 10 170 l S\n", $marks, 'left of the top-left trim corner, on the top trim line (y = 200 − 30)');
        self::assertStringContainsString("30 198 m 30 190 l S\n", $marks, 'above the top-left trim corner');
        self::assertStringContainsString("270 10 m 270 2 l S\n", $marks, 'below the bottom-right trim corner');

        $resources = $page->get('Resources');
        self::assertInstanceOf(PdfDict::class, $resources);
        $spaces = $resources->get('ColorSpace');
        self::assertInstanceOf(PdfDict::class, $spaces);
        $registration = $spaces->get('WbRegistration');
        self::assertInstanceOf(PdfRef::class, $registration);
        self::assertSame(
            '[ /Separation /All /DeviceCMYK << /FunctionType 2 /Domain [ 0 1 ] /C0 [ 0 0 0 0 ] /C1 [ 1 1 1 1 ] /N 1 >> ]',
            PdfSyntax::serialize((new PdfReader($out))->value($registration->number)),
            'marks print on all four plates',
        );

        // the font and its program travel untouched
        self::assertSame('FONT', $this->data($out, 12));
        self::assertSame('Demo', $this->dict($out, 11)->name('FontName'));
        self::assertFalse($page->has('StructParents'));
        PdfRgbVerifier::assertNoRgb($out);
    }

    public function testGradientsAndTheirOpacityMask(): void
    {
        $out = $this->convert($this->pdf(
            "/G1 gs /Pattern cs /P1 scn 0 0 100 100 re f /Sh1 sh",
            '<< /ExtGState << /G1 20 0 R /G0 25 0 R >> /Pattern << /P1 21 0 R >> /Shading << /Sh1 27 0 R >> >>',
            [
                // how Chromium writes a gradient with stop opacity: the colours in a shading pattern,
                // the opacity as a luminosity mask painted with a grey gradient in a DeviceRGB group
                20 => '<< /Type /ExtGState /SMask << /Type /Mask /S /Luminosity /G 22 0 R >> >>',
                21 => '<< /Type /Pattern /PatternType 2 /Matrix [1 0 0 1 0 0] /Shading << /ShadingType 2 /ColorSpace /DeviceRGB /Coords [0 0 1 0] /Extend [true true]'
                    . ' /Function << /FunctionType 3 /Domain [0 1] /Bounds [.5] /Encode [0 1 0 1] /Functions ['
                    . ' << /FunctionType 2 /Domain [0 1] /C0 [' . self::BRAND . '] /C1 [1 1 1] /N 1 >> 24 0 R ] >> >> >>',
                22 => ['<< /Type /XObject /Subtype /Form /BBox [0 0 100 100] /Group << /Type /Group /S /Transparency /I true /CS /DeviceRGB >> /Resources << /Pattern << /P5 23 0 R >> /ExtGState << /G0 25 0 R >> >> >>', '/G0 gs /Pattern cs /P5 scn 0 0 100 100 re f*'],
                23 => '<< /Type /Pattern /PatternType 2 /Shading << /ShadingType 3 /ColorSpace /DeviceRGB /Coords [.5 .5 0 .5 .5 .5] /Function << /FunctionType 2 /Domain [0 1] /C0 [1 1 1] /C1 [.4 .4 .4] /N 1 >> >> >>',
                24 => '<< /FunctionType 2 /Domain [0 1] /C0 [1 1 1] /C1 [0 0 0] /N 1 >>',
                25 => '<< /Type /ExtGState /BM /Normal /ca .5 /CA 1 >>',
                // a sampled function: red, a neutral grey, the brand colour
                26 => ['<< /FunctionType 0 /Domain [0 1] /Size [3] /BitsPerSample 8 /Range [0 1 0 1 0 1] >>', "\xFF\x00\x00\x80\x80\x80\x5B\x47\xA8"],
                27 => '<< /ShadingType 2 /ColorSpace /DeviceRGB /Coords [0 0 1 0] /Function 26 0 R /Background [0 0 0] >>',
            ],
        ));

        $pattern = $this->dict($out, 21)->get('Shading');
        self::assertInstanceOf(PdfDict::class, $pattern);
        self::assertSame('DeviceCMYK', $pattern->name('ColorSpace'));
        self::assertSame(
            '<< /FunctionType 3 /Domain [ 0 1 ] /Bounds [ .5 ] /Encode [ 0 1 0 1 ] /Functions [ << /FunctionType 2 /Domain [ 0 1 ] /C0 [ 0.75 0.8 0 0.05 ] /C1 [ 0 0 0 0 ] /N 1 >> 24 0 R ] >>',
            PdfSyntax::serialize($pattern->get('Function')),
        );
        self::assertSame('<< /FunctionType 2 /Domain [ 0 1 ] /C0 [ 0 0 0 0 ] /C1 [ 0 0 0 1 ] /N 1 >>', PdfSyntax::serialize((new PdfReader($out))->value(24)), 'white → black is a ramp of black ink alone');

        // the mask: a DeviceGray group painted with the same luminosities
        $state = $this->dict($out, 20)->get('SMask');
        self::assertInstanceOf(PdfDict::class, $state);
        self::assertSame('Luminosity', $state->name('S'));
        $form = $this->dict($out, 22);
        $group = $form->get('Group');
        self::assertInstanceOf(PdfDict::class, $group);
        self::assertSame('DeviceGray', $group->name('CS'));
        $maskShading = $this->dict($out, 23)->get('Shading');
        self::assertInstanceOf(PdfDict::class, $maskShading);
        self::assertSame('DeviceGray', $maskShading->name('ColorSpace'));
        self::assertSame('<< /FunctionType 2 /Domain [ 0 1 ] /C0 [ 1 ] /C1 [ 0.4 ] /N 1 >>', PdfSyntax::serialize($maskShading->get('Function')), 'opacity 100 % → 40 % stays exactly that');

        // the plain graphics state is shared by the page and the mask: written once
        self::assertSame('<< /Type /ExtGState /BM /Normal /ca .5 /CA 1 >>', PdfSyntax::serialize((new PdfReader($out))->value(25)));

        $sampled = $this->dict($out, 26);
        self::assertSame(['0', '1', '0', '1', '0', '1', '0', '1'], $sampled->get('Range'));
        $samples = array_map(ord(...), str_split($this->data($out, 26)));
        self::assertCount(12, $samples);
        self::assertSame([0, 0, 0, 127], array_slice($samples, 4, 4), 'the grey sample: K only');
        self::assertSame([191, 204, 0, 13], array_slice($samples, 8, 4), 'the brand sample: 75 / 80 / 0 / 5');
        self::assertGreaterThan(240, $samples[1] + 0, 'red: magenta');
        self::assertSame(['0', '0', '0', '1'], $this->dict($out, 27)->get('Background'));
        PdfRgbVerifier::assertNoRgb($out);
    }

    public function testPicturesKeepTheirPixelsAndMasks(): void
    {
        $jpeg = self::jpeg(16, 8, 'rgb(255,0,0)');
        $neutral = str_repeat("\x00\x00\x00\xFF\xFF\xFF\x80\x80\x80", 4);
        $colours = str_repeat("\xFF\x00\x00", 3) . "\x00\x00\x00";
        $out = $this->convert($this->pdf(
            'q 100 0 0 50 0 0 cm /Im1 Do Q /Fm1 Do',
            '<< /XObject << /Im1 30 0 R /Fm1 34 0 R >> >>',
            [
                30 => [sprintf('<< /Type /XObject /Subtype /Image /Width 16 /Height 8 /BitsPerComponent 8 /ColorSpace [/ICCBased 37 0 R] /ColorTransform 0 /Filter /DCTDecode /Length %d >>', strlen($jpeg)), $jpeg],
                // a black shadow with its soft mask, as Chromium rasterises an SVG filter
                31 => ['<< /Type /XObject /Subtype /Image /Width 4 /Height 3 /BitsPerComponent 8 /ColorSpace /DeviceRGB /SMask 32 0 R /Filter /FlateDecode >>', (string) gzcompress($neutral)],
                32 => ['<< /Type /XObject /Subtype /Image /Width 4 /Height 3 /BitsPerComponent 8 /ColorSpace /DeviceGray /Filter /FlateDecode >>', (string) gzcompress(str_repeat("\x40", 12))],
                33 => ['<< /Type /XObject /Subtype /Image /Width 2 /Height 2 /BitsPerComponent 8 /ColorSpace /DeviceRGB /SMask 32 0 R >>', $colours],
                34 => ['<< /Type /XObject /Subtype /Form /BBox [0 0 10 10] /Group << /S /Transparency /I true >> /Resources << /XObject << /A 31 0 R /B 33 0 R /C 35 0 R /D 30 0 R >> >> >>', '/A Do /B Do /C Do /D Do 1 0 0 rg'],
                35 => ['<< /Type /XObject /Subtype /Image /Width 2 /Height 1 /BitsPerComponent 8 /ColorSpace [/Indexed /DeviceRGB 1 <000000 5B47A8>] >>', "\x00\x01"],
                37 => ['<< /N 3 /Filter /FlateDecode >>', (string) gzcompress('not a real profile')],
            ],
        ));

        $photo = $this->dict($out, 30);
        self::assertSame('DeviceCMYK', $photo->name('ColorSpace'));
        self::assertSame('DCTDecode', $photo->name('Filter'));
        self::assertSame(['1', '0', '1', '0', '1', '0', '1', '0'], $photo->get('Decode'), 'ImageMagick writes Adobe-style inverted CMYK');
        self::assertFalse($photo->has('ColorTransform'));
        self::assertSame(4, PdfRgbVerifier::jpegComponents($this->raw($out, 30)));
        $converted = new \Imagick();
        $converted->readImageBlob($this->raw($out, 30));
        self::assertSame([16, 8], [$converted->getImageWidth(), $converted->getImageHeight()], 'no resampling');
        self::assertFalse((new PdfReader($out))->has(37), 'the RGB profile is not carried over');

        $shadow = $this->dict($out, 31);
        self::assertSame('DeviceGray', $shadow->name('ColorSpace'), 'every pixel is neutral: black ink only');
        self::assertSame("\x00\xFF\x80\x00\xFF\x80\x00\xFF\x80\x00\xFF\x80", $this->data($out, 31));
        self::assertEquals(new PdfRef(32), $shadow->get('SMask'));
        self::assertSame(str_repeat("\x40", 12), $this->data($out, 32), 'the soft mask is untouched');

        $coloured = $this->dict($out, 33);
        self::assertSame('DeviceCMYK', $coloured->name('ColorSpace'));
        self::assertSame('FlateDecode', $coloured->name('Filter'));
        $pixels = array_map(ord(...), str_split($this->data($out, 33)));
        self::assertCount(16, $pixels);
        self::assertLessThan(8, $pixels[0], 'red through the profile: no cyan');
        self::assertGreaterThan(240, $pixels[1]);
        self::assertGreaterThan(240, $pixels[2]);
        self::assertGreaterThan(600, array_sum(array_slice($pixels, 12, 4)), 'a black pixel of a colour picture is the profile\'s black — pictures are not torn apart by the vector rules');

        self::assertSame('[ /Indexed /DeviceCMYK 1 <000000ffbfcc000d> ]', PdfSyntax::serialize($this->dict($out, 35)->get('ColorSpace')), 'a colour table is flat artwork: K 100 and the brand CMYK');
        self::assertMatchesRegularExpression('~/D Do\n0 0\.9\d* 1 0 k\n$~', $this->data($out, 34), 'the form\'s own content is converted too');
        PdfRgbVerifier::assertNoRgb($out);

        // a second chunk with the same photo takes the conversion from the first
        $second = $this->directory . '/second.pdf';
        $pass = self::converter()->pass([], $this->directory);
        self::converter()->convert($this->pdf('/Im1 Do', '<< /XObject << /Im1 30 0 R >> >>', [30 => ['<< /Type /XObject /Subtype /Image /Width 16 /Height 8 /BitsPerComponent 8 /ColorSpace /DeviceRGB /Filter /DCTDecode >>', $jpeg]], 'a.pdf'), $second, $pass);
        self::converter()->convert($this->pdf('/Im1 Do', '<< /XObject << /Im1 30 0 R >> >>', [30 => ['<< /Type /XObject /Subtype /Image /Width 16 /Height 8 /BitsPerComponent 8 /ColorSpace /DeviceRGB /Filter /DCTDecode >>', $jpeg]], 'b.pdf'), $this->directory . '/third.pdf', $pass);
        self::assertSame(['pictures' => 2, 'converted' => 1, 'reused' => 1], $pass->counts());
        self::assertSame($this->raw($second, 30), $this->raw($this->directory . '/third.pdf', 30));
    }

    public function testType3GlyphsAndNamedColourSpaces(): void
    {
        $out = $this->convert($this->pdf(
            '/CS0 cs 1 0 0 sc /Sep cs 1 scn BT /F3 10 Tf (A) Tj ET',
            '<< /ColorSpace << /CS0 [/ICCBased 41 0 R] /Sep [/Separation /Gold /DeviceCMYK << /FunctionType 2 /Domain [0 1] /C0 [0 0 0 0] /C1 [0 .2 .8 0] /N 1 >>] >> /Font << /F3 40 0 R >> >>',
            [
                40 => '<< /Type /Font /Subtype /Type3 /FontBBox [0 0 10 10] /FontMatrix [.1 0 0 .1 0 0] /CharProcs << /A 42 0 R >> /Encoding << /Type /Encoding /Differences [65 /A] >> /FirstChar 65 /LastChar 65 /Widths [10] /Resources << >> >>',
                41 => ['<< /N 3 >>', 'profile'],
                42 => ['<< >>', '10 0 d0 0 0 1 rg 0 0 10 10 re f'],
            ],
        ));

        $content = $this->data($out, $this->contentNumber($out));
        self::assertStringStartsWith("/CS0 cs\n", $content);
        self::assertMatchesRegularExpression('~^/CS0 cs\n0(\.0\d*)? 0\.9\d* (1|0\.9\d*) 0(\.0\d*)? sc\n/Sep cs\n1 scn\n~', $content);
        $resources = $this->dict($out, 3)->get('Resources');
        self::assertInstanceOf(PdfDict::class, $resources);
        $spaces = $resources->get('ColorSpace');
        self::assertInstanceOf(PdfDict::class, $spaces);
        self::assertEquals(new PdfName('DeviceCMYK'), $spaces->get('CS0'), 'the named sRGB space now says DeviceCMYK');
        self::assertStringStartsWith('[ /Separation /Gold /DeviceCMYK', PdfSyntax::serialize($spaces->get('Sep')), 'a spot colour with a CMYK fallback is kept');
        self::assertMatchesRegularExpression('~^10 0 d0\n[\d. ]+ k\n0 0 10 10 re\nf\n$~', $this->data($out, 42), 'a coloured glyph is converted like page content');
        PdfRgbVerifier::assertNoRgb($out);
    }

    public function testGhostscriptSeesOnlyTheInksTheRulesGive(): void
    {
        $ghostscript = (new ExecutableFinder())->find('gs');

        if ($ghostscript === null) {
            self::markTestSkipped('Ghostscript is not installed.');
        }

        // black text-like box, a brand box, a 50 % transparent brand box over white
        $out = $this->convert($this->pdf(
            "0 0 0 rg 20 20 60 60 re f\n" . self::BRAND . " rg 120 20 60 60 re f\n/G1 gs 220 20 60 60 re f",
            '<< /ExtGState << /G1 << /Type /ExtGState /BM /Normal /ca .5 >> >> >>',
        ));

        $process = new Process([$ghostscript, '-q', '-dNOPAUSE', '-dBATCH', '-sDEVICE=tiff32nc', '-r72', '-sCompression=none', '-sOutputFile=' . $this->directory . '/page.tif', $out]);
        $process->mustRun();
        $image = new \Imagick($this->directory . '/page.tif');
        // PDF y runs up: y = 50 pt from the bottom of a 200 pt page is row 150
        $ink = static function (int $x) use ($image): array {
            $pixel = $image->getImagePixelColor($x, 150);

            return array_map(static fn (int $channel): int => (int) round($pixel->getColorValue($channel) * 100), [\Imagick::COLOR_CYAN, \Imagick::COLOR_MAGENTA, \Imagick::COLOR_YELLOW, \Imagick::COLOR_BLACK]);
        };

        self::assertSame([0, 0, 0, 100], $ink(50), 'black: ink on the K plate only');
        self::assertSame([75, 80, 0, 5], $ink(150), 'the brand colour: exactly the palette\'s CMYK');
        self::assertEqualsWithDelta([37.5, 40, 0, 2.5], $ink(250), 1.0, 'half-transparent over paper: blended in CMYK');
        self::assertSame([0, 0, 0, 0], $ink(100), 'paper');
    }

    /**
     * @return iterable<string, array{string, string, array<int, string|array{0: string, 1: string}>}>
     */
    public static function refused(): iterable
    {
        yield 'a PostScript calculator function' => ['/Sh1 sh', '<< /Shading << /Sh1 << /ShadingType 2 /ColorSpace /DeviceRGB /Coords [0 0 1 0] /Function 50 0 R >> >> >>', [50 => ['<< /FunctionType 4 /Domain [0 1] /Range [0 1 0 1 0 1] >>', '{ dup dup }']]];
        yield 'a mesh shading' => ['/Sh1 sh', '<< /Shading << /Sh1 50 0 R >> >>', [50 => ['<< /ShadingType 4 /ColorSpace /DeviceRGB /BitsPerCoordinate 8 /BitsPerComponent 8 /BitsPerFlag 8 /Decode [0 1 0 1 0 1 0 1 0 1] >>', 'mesh']]];
        yield 'an uncoloured tiling pattern' => ['/Pattern cs /P1 scn', '<< /Pattern << /P1 50 0 R >> >>', [50 => ['<< /Type /Pattern /PatternType 1 /PaintType 2 /TilingType 1 /BBox [0 0 1 1] /XStep 1 /YStep 1 /Resources << >> >>', '0 0 1 1 re f']]];
        yield 'a spot colour that falls back to RGB' => ['/Sp cs 1 scn', '<< /ColorSpace << /Sp [/Separation /Gold /DeviceRGB << /FunctionType 2 /Domain [0 1] /C0 [1 1 1] /C1 [1 .8 0] /N 1 >>] >> >>', []];
        yield 'a Lab colour space' => ['/L cs 50 0 0 sc', '<< /ColorSpace << /L [/Lab << /WhitePoint [.95 1 1.09] >>] >> >>', []];
        yield 'a JPEG 2000 picture' => ['/Im Do', '<< /XObject << /Im 50 0 R >> >>', [50 => ['<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceRGB /Filter /JPXDecode >>', 'jp2']]];
        yield 'a picture with a colour-key mask' => ['/Im Do', '<< /XObject << /Im 50 0 R >> >>', [50 => ['<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceRGB /Mask [0 0 0 0 0 0] >>', 'abc']]];
        yield 'a transfer function' => ['/G gs', '<< /ExtGState << /G << /Type /ExtGState /TR 50 0 R >> >> >>', [50 => '<< /FunctionType 2 /Domain [0 1] /N 1 >>']];
        yield 'an unknown blend mode' => ['/G gs', '<< /ExtGState << /G << /BM /Sparkle >> >> >>', []];
        yield 'a form without resources' => ['/Fm Do', '<< /XObject << /Fm 50 0 R >> >>', [50 => ['<< /Type /XObject /Subtype /Form /BBox [0 0 1 1] >>', '1 0 0 rg']]];
        yield 'an unknown operator in a form' => ['/Fm Do', '<< /XObject << /Fm 50 0 R >> >>', [50 => ['<< /Type /XObject /Subtype /Form /BBox [0 0 1 1] /Resources << >> >>', '1 0 0 rg 1 zz']]];
        yield 'an unknown kind of resource' => ['', '<< /Sparkles << /S1 << >> >> >>', []];
        yield 'a colour picture inside a luminosity mask' => ['/G gs', '<< /ExtGState << /G << /SMask << /S /Luminosity /G 50 0 R >> >> >> >>', [
            50 => ['<< /Type /XObject /Subtype /Form /BBox [0 0 1 1] /Group << /S /Transparency /CS /DeviceRGB >> /Resources << /XObject << /I 51 0 R >> >> >>', '/I Do'],
            51 => ['<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceRGB >>', "\xFF\x00\x00"],
        ]];
    }

    /**
     * @param array<int, string|array{0: string, 1: string}> $objects
     */
    #[DataProvider('refused')]
    public function testWhatHasNoRuleFailsTheConversionAndLeavesNoFile(string $content, string $resources, array $objects): void
    {
        $source = $this->pdf($content, $resources, $objects);
        $target = $this->directory . '/out.pdf';

        try {
            self::converter()->convert($source, $target, self::converter()->pass([], $this->directory));
            self::fail('The conversion should have failed.');
        } catch (CmykConversionFailed $failure) {
            self::assertNotSame('', $failure->getMessage());
        }

        self::assertFileDoesNotExist($target);
    }

    public function testAnUnknownPageEntryFailsToo(): void
    {
        $this->expectException(CmykConversionFailed::class);
        $this->expectExceptionMessage('/SeparationInfo');

        $this->convert($this->pdf('', '<< >>', [], 'in.pdf', ' /SeparationInfo << >>'));
    }

    private static function converter(): PdfCmykConverter
    {
        return new PdfCmykConverter(CmykColorRulesTest::icc());
    }

    private function convert(string $source, null|CropMarkLines $marks = null): string
    {
        $target = $this->directory . '/out-' . bin2hex(random_bytes(3)) . '.pdf';
        $converter = self::converter();
        $converter->convert($source, $target, $converter->pass(['#5b47a8' => new CmykColor(75, 80, 0, 5)], $this->directory), $marks);

        return $target;
    }

    /**
     * A one-page PDF (300 × 200 pt): 1 = catalog, 2 = page tree, 3 = the page,
     * 4 = its content; `$objects` are added under their numbers — a string is
     * a plain object, `[dictionary, data]` a stream.
     *
     * @param array<int, string|array{0: string, 1: string}> $objects
     */
    private function pdf(string $content, string $resources, array $objects = [], string $name = 'in.pdf', string $pageExtra = ''): string
    {
        $path = $this->directory . '/' . $name;
        $writer = new PdfWriter($path, '1.4');
        $parse = static function (string $source): mixed {
            $at = 0;

            return PdfSyntax::parse($source, $at);
        };
        $writer->object(1, $parse('<< /Type /Catalog /Pages 2 0 R >>'));
        $writer->object(2, $parse('<< /Type /Pages /Kids [3 0 R] /Count 1 >>'));
        $writer->object(3, $parse('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /StructParents 0 /Tabs /S /Resources ' . $resources . ' /Contents 4 0 R' . $pageExtra . ' >>'));
        $writer->stream(4, new PdfDict(['Filter' => new PdfName('FlateDecode')]), (string) gzcompress($content));
        $highest = 4;

        foreach ($objects as $number => $object) {
            $highest = max($highest, $number);

            if (is_string($object)) {
                $writer->object($number, $parse($object));

                continue;
            }

            $dictionary = $parse($object[0]);
            self::assertInstanceOf(PdfDict::class, $dictionary);
            $writer->stream($number, $dictionary, $object[1]);
        }

        $writer->finish(1, $highest + 1);

        return $path;
    }

    private function dict(string $path, int $number): PdfDict
    {
        $value = (new PdfReader($path))->value($number);
        self::assertInstanceOf(PdfDict::class, $value);

        return $value;
    }

    private function raw(string $path, int $number): string
    {
        return (new PdfReader($path))->streamBytes($number);
    }

    /** The decoded stream data. */
    private function data(string $path, int $number): string
    {
        $bytes = $this->raw($path, $number);

        if ($this->dict($path, $number)->name('Filter') !== 'FlateDecode') {
            return $bytes;
        }

        $data = gzuncompress($bytes);
        self::assertIsString($data);

        return $data;
    }

    private function contentNumber(string $path): int
    {
        $contents = $this->dict($path, 3)->get('Contents');
        self::assertInstanceOf(PdfRef::class, $contents);

        return $contents->number;
    }

    private static function jpeg(int $width, int $height, string $color): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel($color));
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(90);

        return $image->getImageBlob();
    }
}
