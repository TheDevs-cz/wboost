<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Image;

/**
 * Turns arbitrary picture bytes into a format the WHOLE stack can read.
 *
 * "The whole stack" is the constraint that matters here: an uploaded image has
 * to survive `getimagesizefromstring()` (the natural-size read behind every
 * placeholder fill), a browser `<img>` (gallery thumbnails, the fill page's
 * live Fabric preview) AND Gotenberg's headless Chromium (the export render).
 * Modern phone formats — HEIC/HEIF above all, the default camera format on
 * every recent iPhone — fail all three: PHP cannot size them, Chrome cannot
 * display them. Before this normalisation such a file uploaded fine, showed a
 * broken thumbnail, and then failed the export with
 * "could not be read or is not a supported raster image".
 *
 * So anything that is not already one of the four universally supported raster
 * formats is transcoded through ImageMagick to JPEG (or PNG when it carries
 * transparency). The result also reports the mime type and pixel size that
 * belong to the ACTUAL bytes, not to the file name — the extension a client
 * sends is a hint, never evidence.
 *
 * SVG is deliberately refused rather than rasterized: it is a first-class
 * vector asset everywhere else in the app (logos on the canvas, backgrounds)
 * and must stay vector. Callers keep SVG as-is.
 */
readonly final class NormalizeImageFormat
{
    private const int JPEG_QUALITY = 90;

    /**
     * Formats every consumer already agrees on: PHP sizes them, browsers paint
     * them, Chromium prints them. They pass through byte-for-byte.
     *
     * HEIF is deliberately absent even though PHP 8.5 knows the type: it fails
     * outright on real iPhone captures (the reported bug — a 3024×4032 photo
     * getimagesize()s to false) and on the ones it does read it reports the
     * tile size rather than the picture's. And no browser would paint it.
     *
     * @var array<int, array{string, string}> IMAGETYPE_* → [extension, mime type]
     */
    private const array PASSTHROUGH = [
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_GIF => ['gif', 'image/gif'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    /**
     * @return null|array{contents: string, extension: string, mimeType: string, width: int, height: int}
     *   null when the bytes are not a raster picture this app handles (an SVG,
     *   a non-image, or something ImageMagick cannot decode) — the caller then
     *   decides what that means for it.
     */
    public function normalize(string $contents): null|array
    {
        if ($contents === '') {
            return null;
        }

        $size = @getimagesizefromstring($contents);

        if ($size !== false && isset(self::PASSTHROUGH[$size[2]]) && $size[0] > 0 && $size[1] > 0) {
            [$extension, $mimeType] = self::PASSTHROUGH[$size[2]];

            return [
                'contents' => $contents,
                'extension' => $extension,
                'mimeType' => $mimeType,
                'width' => $size[0],
                'height' => $size[1],
            ];
        }

        if (self::looksLikeSvg($contents)) {
            return null;
        }

        return $this->transcode($contents);
    }

    /**
     * @return null|array{contents: string, extension: string, mimeType: string, width: int, height: int}
     */
    private function transcode(string $contents): null|array
    {
        try {
            $image = new \Imagick();
            $image->readImageBlob($contents);

            // A HEIC/AVIF container can hold several images (burst shots, depth
            // maps, thumbnails); the primary picture is the first one.
            $image->setIteratorIndex(0);

            // Phone photos record "which way is up" as an EXIF flag instead of
            // rotating the pixels. Bake it in, because the flag is about to be
            // stripped and because getimagesizefromstring() reports the stored
            // (unrotated) size — every consumer must agree on the orientation.
            $image->autoOrient();

            $hasAlpha = $image->getImageAlphaChannel();

            // Keep the colour profile across the strip: an iPhone photo is
            // Display P3, and dropping its profile leaves Chrome treating wide
            // -gamut pixels as sRGB — visibly oversaturated.
            $iccProfile = $image->getImageProfiles('icc', true)['icc'] ?? null;
            $image->stripImage();

            if (is_string($iccProfile) && $iccProfile !== '') {
                $image->profileImage('icc', $iccProfile);
            }

            if ($hasAlpha) {
                $image->setImageFormat('png');
                $extension = 'png';
                $mimeType = 'image/png';
            } else {
                $image->setImageFormat('jpeg');
                $image->setImageCompressionQuality(self::JPEG_QUALITY);
                $extension = 'jpg';
                $mimeType = 'image/jpeg';
            }

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            // getImageBlob() (not getImagesBlob()) writes the CURRENT frame
            // only — a multi-frame source must not become a multi-frame JPEG.
            $bytes = $image->getImageBlob();

            $image->clear();
        } catch (\ImagickException | \ImagickPixelException) {
            return null;
        }

        if ($bytes === '' || $width < 1 || $height < 1) {
            return null;
        }

        return [
            'contents' => $bytes,
            'extension' => $extension,
            'mimeType' => $mimeType,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Sniffs the leading bytes for an SVG root (possibly preceded by an XML
     * declaration, a doctype or comments). Content, not file name — an SVG
     * uploaded as "logo.txt" is still an SVG.
     */
    private static function looksLikeSvg(string $contents): bool
    {
        $head = substr($contents, 0, 1024);

        return stripos($head, '<svg') !== false;
    }
}
