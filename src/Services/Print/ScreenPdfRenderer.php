<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print;

use Psr\Clock\ClockInterface;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\PrintPageGeometry;
use WBoost\Web\Value\RenderImageFormat;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;
use WBoost\Web\Value\ScreenPdfPage;
use WBoost\Web\Value\TemplateDimension;

/**
 * The screen PDF of a document ("PDF k odeslání"): one page per filled
 * variant, light enough to e-mail. Works for ANY dimension, px included.
 *
 * Each page is the ordinary PNG render (no print layer — no bleed, no black
 * ink handling), downscaled to {@see SCREEN_PPI} for physical units (px
 * canvases keep their pixels), flattened on white and stored as an sRGB
 * JPEG; the PDF page is the trim size ({@see ScreenPdfWriter}).
 */
final readonly class ScreenPdfRenderer
{
    public const int SCREEN_PPI = 150;
    public const int JPEG_QUALITY = 85;

    /** A px canvas maps to the page at 96 px per inch: 1 px = 0.75 pt. */
    public const float PX_TO_PT = 0.75;

    public function __construct(
        private TemplateVariantImageRendererInterface $renderer,
        private ScreenPdfWriter $writer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<array{TemplateVariant, ResolvedInputOverrides, null|ResolvedImageOverrides}> $pages
     *
     * @throws \WBoost\Web\Exceptions\ContainerOverflow
     * @throws \WBoost\Web\Exceptions\TemplateRenderUnavailable
     */
    public function renderDocument(array $pages, string $title, bool $strictContainerOverflow = false): string
    {
        if ($pages === []) {
            throw new \InvalidArgumentException('A screen PDF needs at least one page.');
        }

        $screenPages = [];
        foreach ($pages as [$variant, $overrides, $imageOverrides]) {
            $screenPages[] = $this->renderPage($variant, $overrides, $imageOverrides, $strictContainerOverflow);
        }

        return $this->writer->write($screenPages, $title, $this->clock->now());
    }

    /**
     * @throws \WBoost\Web\Exceptions\ContainerOverflow
     * @throws \WBoost\Web\Exceptions\TemplateRenderUnavailable
     */
    public function renderPage(
        TemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides,
        bool $strictContainerOverflow = false,
    ): ScreenPdfPage {
        $png = $this->renderer->renderToBytes(
            $variant,
            $overrides,
            $imageOverrides,
            $strictContainerOverflow,
            format: RenderImageFormat::Png,
        );

        $dimension = $variant->dimension;
        [$widthPt, $heightPt] = self::pageSizePt($dimension);
        [$jpeg, $width, $height] = self::toJpeg($png, ...self::rasterSize($dimension));

        return new ScreenPdfPage($jpeg, $width, $height, $widthPt, $heightPt);
    }

    /**
     * The page (= trim) size in PostScript points.
     *
     * @return array{float, float}
     */
    public static function pageSizePt(TemplateDimension $dimension): array
    {
        return match ($dimension->unit) {
            DimensionUnit::Mm => [PrintPageGeometry::mmToPt($dimension->unitWidth), PrintPageGeometry::mmToPt($dimension->unitHeight)],
            DimensionUnit::Cm => [PrintPageGeometry::mmToPt($dimension->unitWidth * 10), PrintPageGeometry::mmToPt($dimension->unitHeight * 10)],
            DimensionUnit::Px => [$dimension->width() * self::PX_TO_PT, $dimension->height() * self::PX_TO_PT],
        };
    }

    /**
     * Target raster size: 150 ppi of the trim for mm / cm, the canvas pixels
     * for px.
     *
     * @return array{int, int}
     */
    public static function rasterSize(TemplateDimension $dimension): array
    {
        if ($dimension->unit === DimensionUnit::Px) {
            return [$dimension->width(), $dimension->height()];
        }

        $scale = self::SCREEN_PPI / DimensionUnit::PRINT_DPI;

        return [
            max(1, (int) round($dimension->width() * $scale)),
            max(1, (int) round($dimension->height() * $scale)),
        ];
    }

    /**
     * Downscale FIRST (alpha intact — one full-size copy at most), then
     * flatten on white, then an sRGB JPEG.
     *
     * @return array{string, int, int}
     */
    public static function toJpeg(string $png, int $width, int $height): array
    {
        $image = new \Imagick();
        $image->readImageBlob($png);

        if ($image->getImageWidth() !== $width || $image->getImageHeight() !== $height) {
            $image->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);
        }

        $image->setImageBackgroundColor('white');
        $flat = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
        $image->clear();

        $flat->setImageAlphaChannel(\Imagick::ALPHACHANNEL_OFF);
        $flat->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
        // A grey-only design would otherwise come out as a 1-channel JPEG,
        // which the DeviceRGB image dictionary would misread. It takes the
        // WAND-level type (`-type TrueColor`); setImageType() alone does not
        // stop the JPEG coder's auto-grayscale.
        $flat->setImageType(\Imagick::IMGTYPE_TRUECOLOR);
        $flat->setType(\Imagick::IMGTYPE_TRUECOLOR);
        $flat->stripImage();
        $flat->setImageDepth(8);
        $flat->setImageFormat('jpeg');
        $flat->setImageCompressionQuality(self::JPEG_QUALITY);

        $jpeg = $flat->getImageBlob();
        $result = [$jpeg, $flat->getImageWidth(), $flat->getImageHeight()];
        $flat->clear();

        return $result;
    }
}
