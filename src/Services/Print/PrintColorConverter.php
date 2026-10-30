<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print;

use WBoost\Web\Value\PrintRaster;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use WBoost\Web\Value\CmykColor;

/**
 * Turns the print passes (RGB PNGs from headless Chromium) into the one CMYK
 * raster the PDF carries:
 *
 *  1. flatten on white paper (a transparent design prints on the paper);
 *  2. sRGB → Coated FOGRA39 through lcms — relative colorimetric with black
 *     point compensation, Adobe's default for this conversion;
 *  3. "keep the numbers": every pixel whose RGB is EXACTLY a brand colour
 *     gets the manual's CMYK (solid fills, text interiors — anti-aliased
 *     edges are blends and keep the ICC values, invisibly);
 *  4. black-ink overprint: the mask pass's alpha raises K towards 100 %
 *     (`K + a·(1 − K)`, i.e. screen) while C/M/Y underneath stay — black
 *     text printed ON TOP of the background, never knocked out of it, so
 *     plate misregistration cannot open a white halo.
 *
 * Output is a baseline CMYK JPEG at high quality with no chroma subsampling
 * (text edges), the "Automaticky (JPEG) / Maximální" of the print preset.
 */
final readonly class PrintColorConverter
{
    public const int JPEG_QUALITY = 95;

    private const int PIXEL_CACHE_MEMORY_BYTES = 256 * 1024 * 1024;

    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/icc/sRGB-v4.icc')]
        private string $rgbProfilePath,
        #[Autowire('%kernel.project_dir%/resources/icc/CoatedFOGRA39.icc')]
        private string $cmykProfilePath,
    ) {
    }

    public function outputProfile(): string
    {
        return $this->readProfile($this->cmykProfilePath);
    }

    /**
     * @param array<string, CmykColor> $brandColors lowercase `#rrggbb` → CMYK,
     *   ideally only the colours the design uses (each one costs a full-size
     *   mask pass)
     */
    public function toCmykJpeg(string $compositePng, null|string $blackInkMaskPng, array $brandColors): PrintRaster
    {
        // ImageMagick is Q16: an A2-with-bleed raster (≈35 Mpx) is ~280 MB per
        // copy, and the conversion holds several — measured 1.3 GB resident
        // uncapped, inside a web request. Capping the in-RAM pixel cache makes
        // ImageMagick spill the rest to its disk cache instead: slower on big
        // formats, but bounded. The limits are process-wide (the FrankenPHP
        // worker keeps the process), so they are restored afterwards.
        $previousMemory = \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MEMORY);
        $previousMap = \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MAP);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, self::PIXEL_CACHE_MEMORY_BYTES);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, self::PIXEL_CACHE_MEMORY_BYTES);

        try {
            return $this->convert($compositePng, $blackInkMaskPng, $brandColors);
        } finally {
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, $previousMemory);
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, $previousMap);
        }
    }

    /**
     * @param array<string, CmykColor> $brandColors
     */
    private function convert(string $compositePng, null|string $blackInkMaskPng, array $brandColors): PrintRaster
    {
        $rgb = new \Imagick();
        $rgb->readImageBlob($compositePng);
        $rgb = $this->flattenOnWhite($rgb);

        $cmyk = clone $rgb;
        $cmyk->setOption('black-point-compensation', 'true');
        $cmyk->setImageArtifact('black-point-compensation', 'true');
        $cmyk->setImageRenderingIntent(\Imagick::RENDERINGINTENT_RELATIVE);
        $cmyk->profileImage('icc', $this->readProfile($this->rgbProfilePath));
        $cmyk->profileImage('icc', $this->readProfile($this->cmykProfilePath));

        foreach ($brandColors as $hex => $color) {
            $cmyk = $this->applyBrandColor($cmyk, $rgb, $hex, $color);
        }

        $rgb->clear();

        if ($blackInkMaskPng !== null) {
            $cmyk = $this->overprintBlackInk($cmyk, $blackInkMaskPng);
        }

        $width = $cmyk->getImageWidth();
        $height = $cmyk->getImageHeight();

        $cmyk->stripImage();
        $cmyk->setImageDepth(8);
        $cmyk->setImageFormat('jpeg');
        $cmyk->setImageCompressionQuality(self::JPEG_QUALITY);
        $cmyk->setSamplingFactors(['1x1', '1x1', '1x1', '1x1']);

        return new PrintRaster($cmyk->getImageBlob(), $width, $height);
    }

    private function flattenOnWhite(\Imagick $image): \Imagick
    {
        $image->setImageBackgroundColor('white');
        $flat = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
        $flat->setImageAlphaChannel(\Imagick::ALPHACHANNEL_OFF);
        $flat->setImageColorspace(\Imagick::COLORSPACE_SRGB);

        return $flat;
    }

    private function applyBrandColor(\Imagick $cmyk, \Imagick $rgb, string $hex, CmykColor $color): \Imagick
    {
        // Exact-match mask: matching pixels go transparent, the alpha
        // channel (negated) is then white exactly there.
        $mask = clone $rgb;
        $mask->setImageAlphaChannel(\Imagick::ALPHACHANNEL_OPAQUE);
        $mask->transparentPaintImage($hex, 0.0, 0, false);
        $mask->separateImageChannel(\Imagick::CHANNEL_ALPHA);
        $mask->negateImage(false);

        if ($mask->getImageChannelMean(\Imagick::CHANNEL_GRAY)['mean'] <= 0.0) {
            return $cmyk;
        }

        $solid = new \Imagick();
        $solid->newImage($cmyk->getImageWidth(), $cmyk->getImageHeight(), new \ImagickPixel(sprintf(
            'cmyk(%s%%,%s%%,%s%%,%s%%)',
            $color->c,
            $color->m,
            $color->y,
            $color->k,
        )));
        $solid->setImageColorspace(\Imagick::COLORSPACE_CMYK);
        $solid->setImageAlphaChannel(\Imagick::ALPHACHANNEL_OPAQUE);
        $solid->compositeImage($mask, \Imagick::COMPOSITE_COPYOPACITY, 0, 0);

        $cmyk->compositeImage($solid, \Imagick::COMPOSITE_OVER, 0, 0);
        $cmyk->setImageAlphaChannel(\Imagick::ALPHACHANNEL_OFF);
        $solid->clear();
        $mask->clear();

        return $cmyk;
    }

    /**
     * Channel arithmetic on separated greys rather than a CMYK composite:
     * ImageMagick's composite operators treat CMYK channels inconsistently
     * (a channel-limited screen on a CMYK image is a silent no-op), while
     * grey images compose exactly as the formula says.
     */
    private function overprintBlackInk(\Imagick $cmyk, string $maskPng): \Imagick
    {
        $alpha = new \Imagick();
        $alpha->readImageBlob($maskPng);
        $alpha->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
        $alpha->separateImageChannel(\Imagick::CHANNEL_ALPHA);

        $channels = new \Imagick();
        foreach ([\Imagick::CHANNEL_CYAN, \Imagick::CHANNEL_MAGENTA, \Imagick::CHANNEL_YELLOW, \Imagick::CHANNEL_BLACK] as $channel) {
            $grey = clone $cmyk;
            $grey->separateImageChannel($channel);

            if ($channel === \Imagick::CHANNEL_BLACK) {
                $grey->compositeImage($alpha, \Imagick::COMPOSITE_SCREEN, 0, 0);
            }

            $channels->addImage($grey);
        }

        $alpha->clear();
        $cmyk->clear();

        $channels->setFirstIterator();
        $combined = $channels->combineImages(\Imagick::COLORSPACE_CMYK);
        $combined->setImageColorspace(\Imagick::COLORSPACE_CMYK);
        $channels->clear();

        return $combined;
    }

    private function readProfile(string $path): string
    {
        $bytes = file_get_contents($path);

        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException(sprintf('ICC profile "%s" is missing.', $path));
        }

        return $bytes;
    }
}
