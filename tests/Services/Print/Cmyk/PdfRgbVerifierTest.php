<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Print\Cmyk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\CmykConversionFailed;
use WBoost\Web\Services\Print\Cmyk\PdfDict;
use WBoost\Web\Services\Print\Cmyk\PdfRgbVerifier;
use WBoost\Web\Services\Print\Cmyk\PdfSyntax;
use WBoost\Web\Services\Print\Cmyk\PdfWriter;

/**
 * The verifier reads a finished file on its own terms — these tests hand it
 * files the converter never saw, each with ONE way of RGB being left.
 *
 * @covers \WBoost\Web\Services\Print\Cmyk\PdfRgbVerifier
 */
final class PdfRgbVerifierTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pdf-verify-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testACleanCmykFilePasses(): void
    {
        $cmykJpeg = self::jpeg(\Imagick::COLORSPACE_CMYK);
        $report = PdfRgbVerifier::assertNoRgb($this->pdf(
            '0 0 0 1 k /DeviceCMYK cs 0 0 0 1 scn .5 g /Pattern cs /P1 scn /X1 Do BT (rg RG /DeviceRGB) Tj ET',
            '<< /XObject << /X1 10 0 R /X2 11 0 R >> /ColorSpace << /C1 [/ICCBased 12 0 R] /C2 [/Separation /All /DeviceCMYK << /FunctionType 2 /Domain [0 1] /C0 [0 0 0 0] /C1 [1 1 1 1] /N 1 >>] >> >>',
            [
                10 => ['<< /Type /XObject /Subtype /Image /Width 8 /Height 8 /BitsPerComponent 8 /ColorSpace /DeviceCMYK /Filter /DCTDecode /Decode [1 0 1 0 1 0 1 0] >>', $cmykJpeg],
                11 => ['<< /Type /XObject /Subtype /Form /BBox [0 0 1 1] /Group << /S /Transparency /CS /DeviceCMYK >> /Resources << >> >>', '0 0 0 0 k f'],
                12 => ['<< /N 4 >>', 'the output profile'],
                // a name in a STRING is not a colour space
                13 => '<< /Title (/DeviceRGB) >>',
            ],
        ));

        self::assertSame(['objects' => 8, 'contentStreams' => 2, 'pictures' => 1], $report);
    }

    /**
     * @return iterable<string, array{string, string, array<int, string|array{0: string, 1: string}>, string}>
     */
    public static function leftovers(): iterable
    {
        yield 'an RGB fill in the page content' => ['0 0 0 1 k 1 0 0 rg f', '<< >>', [], '"rg"'];
        yield 'an RGB stroke in a form' => ['/X1 Do', '<< /XObject << /X1 10 0 R >> >>', [10 => ['<< /Type /XObject /Subtype /Form /BBox [0 0 1 1] /Resources << >> >>', '1 0 0 RG S']], '"RG"'];
        yield 'DeviceRGB selected by name' => ['/DeviceRGB cs 1 0 0 sc', '<< >>', [], 'selects /DeviceRGB'];
        yield 'an RGB picture' => ['', '<< >>', [10 => ['<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceRGB >>', "\xFF\x00\x00"]], 'object 10 names /DeviceRGB'];
        yield 'an object nothing refers to' => ['', '<< >>', [10 => '<< /ShadingType 2 /ColorSpace /DeviceRGB /Coords [0 0 1 0] /Function << /FunctionType 2 /Domain [0 1] /N 1 >> >>'], 'object 10 names /DeviceRGB'];
        yield 'an sRGB-tagged colour space' => ['', '<< /ColorSpace << /C1 [/ICCBased 10 0 R] >> >>', [10 => ['<< /N 3 >>', 'sRGB']], 'ICC-based colour space with 3 components'];
        yield 'an RGB transparency group' => ['', '<< >>', [10 => ['<< /Type /XObject /Subtype /Form /BBox [0 0 1 1] /Group << /S /Transparency /CS /DeviceRGB >> /Resources << >> >>', 'f']], 'object 10 names /DeviceRGB'];
        yield 'a calibrated RGB space' => ['', '<< /ColorSpace << /C1 [/CalRGB << /WhitePoint [1 1 1] >>] >> >>', [], 'names /CalRGB'];
        yield 'a three-channel JPEG labelled CMYK' => ['', '<< >>', [10 => ['<< /Type /XObject /Subtype /Image /Width 8 /Height 8 /BitsPerComponent 8 /ColorSpace /DeviceCMYK /Filter /DCTDecode >>', self::jpeg(\Imagick::COLORSPACE_SRGB)]], 'JPEG with 3 components'];
        yield 'an RGB colour in a Type 3 glyph' => ['', '<< /Font << /F1 10 0 R >> >>', [10 => '<< /Type /Font /Subtype /Type3 /CharProcs << /A 11 0 R >> >>', 11 => ['<< >>', '1 0 d0 1 0 0 rg f']], 'content stream 11'];
        yield 'an inline image' => ['BI /W 1 /H 1 /CS /RGB /BPC 8 ID abc EI', '<< >>', [], 'cannot be verified'];
    }

    /**
     * @param array<int, string|array{0: string, 1: string}> $objects
     */
    #[DataProvider('leftovers')]
    public function testRgbLeftAnywhereFailsTheFile(string $content, string $resources, array $objects, string $says): void
    {
        $this->expectException(CmykConversionFailed::class);
        $this->expectExceptionMessage($says);

        PdfRgbVerifier::assertNoRgb($this->pdf($content, $resources, $objects));
    }

    public function testAFileThatIsNotReadableFails(): void
    {
        file_put_contents($this->directory . '/broken.pdf', str_repeat('not a pdf ', 20));

        $this->expectException(CmykConversionFailed::class);
        $this->expectExceptionMessage('cannot be verified');

        PdfRgbVerifier::assertNoRgb($this->directory . '/broken.pdf');
    }

    public function testJpegFrameHeadersAreFoundBehindMetadataSegments(): void
    {
        self::assertSame(3, PdfRgbVerifier::jpegComponents(self::jpeg(\Imagick::COLORSPACE_SRGB)));
        self::assertSame(4, PdfRgbVerifier::jpegComponents(self::jpeg(\Imagick::COLORSPACE_CMYK)));
        self::assertSame(1, PdfRgbVerifier::jpegComponents(self::jpeg(\Imagick::COLORSPACE_GRAY)));
        self::assertNull(PdfRgbVerifier::jpegComponents('PNG'));
    }

    /**
     * @param array<int, string|array{0: string, 1: string}> $objects
     */
    private function pdf(string $content, string $resources, array $objects = []): string
    {
        $path = $this->directory . '/in.pdf';
        $writer = new PdfWriter($path);
        $parse = static function (string $source): mixed {
            $at = 0;

            return PdfSyntax::parse($source, $at);
        };
        $writer->object(1, $parse('<< /Type /Catalog /Pages 2 0 R >>'));
        $writer->object(2, $parse('<< /Type /Pages /Kids [3 0 R] /Count 1 >>'));
        $writer->object(3, $parse('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Resources ' . $resources . ' /Contents 4 0 R >>'));
        $writer->stream(4, new PdfDict(), $content);
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

    /**
     * @param \Imagick::COLORSPACE_* $colorspace
     */
    private static function jpeg(int $colorspace): string
    {
        $image = new \Imagick();
        $image->newImage(8, 8, new \ImagickPixel('rgb(200,40,40)'));
        $image->transformImageColorspace($colorspace);
        $image->setImageFormat('jpeg');

        return $image->getImageBlob();
    }
}
