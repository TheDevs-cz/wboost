<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Services\Image\ImageDecodeLimits;

/**
 * The first page of an uploaded PDF as a PNG — an uploaded special page is
 * placed as a full-page picture (docs/calendar/architecture.md §C.7). Needs
 * Imagick with Ghostscript; where PDF decoding is unavailable the upload is
 * refused with a message and images still work (risk R8).
 *
 * The page size is the PDF's to choose — up to 5 × 5 m in a file of a few
 * hundred bytes — so the resolution is not fixed: the page is measured first
 * at a few dpi, and rendered at 200 dpi or at whatever lower resolution keeps
 * its long edge at {@see MAX_LONG_EDGE} pixels.
 */
readonly final class RasterizePdfPage
{
    private const int RESOLUTION_DPI = 200;
    private const int MAX_LONG_EDGE = 4000;

    /** Resolution of the measuring pass: the largest page a PDF can declare (14 400 pt) is 800 px. */
    private const int PROBE_DPI = 4;

    public const string UNREADABLE = 'PDF se nepodařilo přečíst. Nahrajte stránku jako obrázek.';

    public static function isPdf(string $contents): bool
    {
        return str_starts_with(ltrim(substr($contents, 0, 1024)), '%PDF');
    }

    /**
     * @return string PNG bytes
     *
     * @throws InvalidCalendarChange
     */
    public function firstPage(string $pdf): string
    {
        $file = tempnam(sys_get_temp_dir(), 'calendar-pdf-');

        if ($file === false) {
            throw new InvalidCalendarChange(self::UNREADABLE);
        }

        try {
            file_put_contents($file, $pdf);
            ImageDecodeLimits::apply();

            $probe = new \Imagick();
            $probe->setResolution(self::PROBE_DPI, self::PROBE_DPI);
            $probe->pingImage('pdf:' . $file . '[0]');
            $longEdgeInches = max($probe->getImageWidth(), $probe->getImageHeight(), 1) / self::PROBE_DPI;
            $probe->clear();

            // One probe pixel is a quarter inch: assume the page that much
            // longer, so the render cannot overshoot the cap.
            $resolution = max(1.0, min(self::RESOLUTION_DPI, self::MAX_LONG_EDGE / ($longEdgeInches + 1 / self::PROBE_DPI)));

            $image = new \Imagick();
            $image->setResolution($resolution, $resolution);
            // `[0]` = only the first page is rendered, whatever the length of the document.
            $image->readImage('pdf:' . $file . '[0]');
            $image->setIteratorIndex(0);

            // A PDF page has no background of its own — print it on white.
            $image->setImageBackgroundColor('white');
            $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $longEdge = max($width, $height);

            if ($longEdge > self::MAX_LONG_EDGE) {
                $scale = self::MAX_LONG_EDGE / $longEdge;
                $image->resizeImage(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), \Imagick::FILTER_LANCZOS, 1);
            }

            $image->stripImage();
            $image->setImageFormat('png');
            $png = $image->getImageBlob();
            $image->clear();
        } catch (\ImagickException | \ImagickPixelException) {
            throw new InvalidCalendarChange(self::UNREADABLE);
        } finally {
            @unlink($file);
        }

        if ($png === '') {
            throw new InvalidCalendarChange(self::UNREADABLE);
        }

        return $png;
    }
}
