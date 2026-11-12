<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print\Cmyk;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use WBoost\Web\Exceptions\CmykConversionFailed;

/**
 * sRGB → Coated FOGRA39 through lcms (Imagick): relative colorimetric with
 * black point compensation — the conversion of the template print pipeline
 * ({@see \WBoost\Web\Services\Print\PrintColorConverter}), here for single
 * colours ({@see swatches()}) and for the pictures of a vector PDF, one file
 * at a time ({@see jpeg()}, {@see raw()}).
 *
 * Pictures are converted file → file. ImageMagick is Q16, so a 5400 × 3600
 * photo is ~300 MB of pixels: the in-RAM pixel cache is capped at 64 MB and
 * everything larger lives in ImageMagick's disk cache, placed beside the
 * target file. Measured on such a photo: 7 s and 50 MB resident with the cap,
 * 7 s and 340 MB without — the export job lives in a container with 768 MB,
 * and the uncapped worker peaked at 605 MB. (The price: ImageMagick skips
 * the optimised Huffman tables when the pixels are on disk, +3.5 % bytes;
 * the pixels are identical.)
 */
readonly final class IccColorTransform
{
    /**
     * What a photo is re-encoded with at least / at most; in between the
     * source's own quality is kept — a higher one would only store the
     * source's artefacts more faithfully, at up to 3 × the bytes (measured:
     * a 9.8 MB upload of quality 81 → 22.5 MB at 81, 30 MB at 90).
     */
    public const int JPEG_QUALITY_MIN = 80;
    public const int JPEG_QUALITY_MAX = 95;

    private const int PIXEL_CACHE_MEMORY_BYTES = 64 * 1024 * 1024;

    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/icc/sRGB-v4.icc')]
        private string $rgbProfilePath,
        #[Autowire('%kernel.project_dir%/resources/icc/CoatedFOGRA39.icc')]
        private string $cmykProfilePath,
    ) {
    }

    public function outputProfile(): string
    {
        return $this->profile($this->cmykProfilePath);
    }

    /**
     * @param list<int> $colors 0xRRGGBB
     *
     * @return array<int, array{float, float, float, float}> colour → C, M, Y, K in 0…1
     */
    public function swatches(array $colors): array
    {
        $colors = array_values(array_unique($colors));

        if ($colors === []) {
            return [];
        }

        $pixels = '';

        foreach ($colors as $color) {
            $pixels .= chr(($color >> 16) & 0xFF) . chr(($color >> 8) & 0xFF) . chr($color & 0xFF);
        }

        try {
            $image = new \Imagick();
            $image->setSize(count($colors), 1);
            $image->setFormat('rgb');
            // @phpstan-ignore method.notFound (Imagick::setDepth() exists at run time; the stubs lack it)
            $image->setDepth(8);
            $image->readImageBlob($pixels);
            $this->toCmyk($image, false);
            /** @var list<float> $values */
            $values = $image->exportImagePixels(0, 0, count($colors), 1, 'CMYK', \Imagick::PIXEL_FLOAT);
            $image->clear();
        } catch (\ImagickException $error) {
            throw new CmykConversionFailed('The colour profile conversion failed: ' . $error->getMessage(), 0, $error);
        }

        if (count($values) !== 4 * count($colors)) {
            throw new CmykConversionFailed('The colour profile conversion answered an unexpected number of values.');
        }

        $table = [];

        foreach ($colors as $index => $color) {
            $table[$color] = [
                self::unit($values[4 * $index]),
                self::unit($values[4 * $index + 1]),
                self::unit($values[4 * $index + 2]),
                self::unit($values[4 * $index + 3]),
            ];
        }

        return $table;
    }

    /**
     * A JPEG file → a CMYK JPEG file of the same pixels (no resampling).
     * The source's embedded profile is honoured; without one it is sRGB.
     *
     * @return array{kind: 'cmyk'|'gray', inverted: bool} `gray`: the JPEG has one channel and was left alone (nothing written)
     */
    public function jpeg(string $source, string $target): array
    {
        return $this->limited(dirname($target), function () use ($source, $target): array {
            try {
                $image = new \Imagick();
                $image->readImage('jpeg:' . $source);

                if ($image->getImageColorspace() === \Imagick::COLORSPACE_GRAY) {
                    $image->clear();

                    return ['kind' => 'gray', 'inverted' => false];
                }

                if ($image->getImageColorspace() === \Imagick::COLORSPACE_CMYK) {
                    throw new CmykConversionFailed('A picture marked as RGB holds CMYK data.');
                }

                $quality = $image->getImageCompressionQuality();
                $this->toCmyk($image, true);
                $image->stripImage();
                $image->setImageDepth(8);
                $image->setImageFormat('jpeg');
                $image->setImageCompressionQuality(max(self::JPEG_QUALITY_MIN, min(self::JPEG_QUALITY_MAX, $quality > 0 ? $quality : self::JPEG_QUALITY_MAX)));
                $image->setSamplingFactors(['1x1', '1x1', '1x1', '1x1']);
                $image->setInterlaceScheme(\Imagick::INTERLACE_NO);
                $image->writeImage('jpeg:' . $target);
                $image->clear();
            } catch (\ImagickException $error) {
                throw new CmykConversionFailed('A photo could not be converted to CMYK: ' . $error->getMessage(), 0, $error);
            }

            return ['kind' => 'cmyk', 'inverted' => self::isInvertedCmykJpeg($target)];
        });
    }

    /**
     * Raw 8-bit RGB pixels (a file of `width × height × 3` bytes, sRGB) → raw
     * 8-bit CMYK pixels (`× 4` bytes, 255 = full ink).
     */
    public function raw(string $source, int $width, int $height, string $target): void
    {
        $this->limited(dirname($target), function () use ($source, $width, $height, $target): bool {
            try {
                $image = new \Imagick();
                $image->setSize($width, $height);
                $image->setFormat('rgb');
                // @phpstan-ignore method.notFound (Imagick::setDepth() exists at run time; the stubs lack it)
                $image->setDepth(8);
                $image->readImage('rgb:' . $source);
                $this->toCmyk($image, false);
                $image->stripImage();
                $image->setImageDepth(8);
                $image->writeImage('cmyk:' . $target);
                $image->clear();
            } catch (\ImagickException $error) {
                throw new CmykConversionFailed('A picture could not be converted to CMYK: ' . $error->getMessage(), 0, $error);
            }

            return true;
        });

        if (@filesize($target) !== $width * $height * 4) {
            throw new CmykConversionFailed('A converted picture has an unexpected size.');
        }
    }

    /**
     * ImageMagick writes CMYK JPEGs the Adobe way — an APP14 "Adobe" segment
     * and INVERTED samples; a PDF reader does not look at APP14, so the image
     * dictionary must say `/Decode [1 0 1 0 1 0 1 0]`.
     */
    public static function isInvertedCmykJpeg(string $path): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 65536);

        return str_contains($head, "\xFF\xEE\x00\x0EAdobe");
    }

    private function toCmyk(\Imagick $image, bool $keepEmbeddedProfile): void
    {
        $image->setOption('black-point-compensation', 'true');
        $image->setImageArtifact('black-point-compensation', 'true');
        $image->setImageRenderingIntent(\Imagick::RENDERINGINTENT_RELATIVE);

        if (!$keepEmbeddedProfile || $image->getImageProfiles('icc', false) === []) {
            $image->profileImage('icc', $this->profile($this->rgbProfilePath));
        }

        $image->profileImage('icc', $this->profile($this->cmykProfilePath));

        if ($image->getImageColorspace() !== \Imagick::COLORSPACE_CMYK) {
            throw new CmykConversionFailed('The colour profile conversion did not produce CMYK.');
        }
    }

    /**
     * @template T
     *
     * @param string $directory where ImageMagick's disk cache goes: the caller's work directory, whose room was checked
     * @param callable(): T $work
     *
     * @return T
     */
    private function limited(string $directory, callable $work): mixed
    {
        // process-wide (the worker keeps the process): restored afterwards
        $memory = \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MEMORY);
        $map = \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MAP);
        // false when it was never set, whatever the stubs say
        $temporary = (string) \Imagick::getRegistry('temporary-path');
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, self::PIXEL_CACHE_MEMORY_BYTES);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, self::PIXEL_CACHE_MEMORY_BYTES);
        \Imagick::setRegistry('temporary-path', $directory);

        try {
            return $work();
        } finally {
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, $memory);
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, $map);
            \Imagick::setRegistry('temporary-path', $temporary !== '' ? $temporary : sys_get_temp_dir());
        }
    }

    private static function unit(float $value): float
    {
        return round(max(0.0, min(1.0, $value)), 4);
    }

    private function profile(string $path): string
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false || $bytes === '') {
            throw new CmykConversionFailed(sprintf('ICC profile "%s" is missing.', $path));
        }

        return $bytes;
    }
}
