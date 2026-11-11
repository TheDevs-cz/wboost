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

    /** Re-encode quality of a picture that is only being rotated — a second lossy pass, so kept high. */
    private const int REORIENT_QUALITY = 95;

    private const int ORIENTATION_UPRIGHT = 1;

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

            $passthrough = [
                'contents' => $contents,
                'extension' => $extension,
                'mimeType' => $mimeType,
                'width' => $size[0],
                'height' => $size[1],
            ];

            if (self::exifOrientation($contents, $size[2]) <= self::ORIENTATION_UPRIGHT || ImageDecodeLimits::exceeds($size[0], $size[1])) {
                return $passthrough;
            }

            // A picture that cannot be rotated is still a picture.
            return $this->reorient($contents, $extension, $mimeType) ?? $passthrough;
        }

        if (self::isText($contents)) {
            return null;
        }

        $announced = ImageDecodeLimits::pixelSize($contents);

        if ($announced === null || ImageDecodeLimits::exceeds($announced[0], $announced[1])) {
            return null;
        }

        return $this->transcode($contents);
    }

    /**
     * An SVG root anywhere in the bytes (an XML declaration, a doctype or
     * comments of any length may precede it). Content, not file name — an SVG
     * uploaded as "logo.txt" is still an SVG. Only meaningful for bytes
     * {@see normalize()} answered null for.
     */
    public static function looksLikeSvg(string $contents): bool
    {
        return self::isText($contents) && stripos($contents, '<svg') !== false;
    }

    /**
     * True for anything that is text rather than a binary picture: SVG, any
     * XML, HTML, plain text, and ImageMagick's script formats (MVG, MSL).
     * Such bytes must never reach ImageMagick — not even for a ping.
     */
    public static function isText(string $contents): bool
    {
        $head = ltrim(substr($contents, 0, 1024), "\xEF\xBB\xBF \t\r\n");

        if (str_starts_with($head, '<')) {
            return true;
        }

        $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        return str_starts_with($mimeType, 'text/')
            || str_contains($mimeType, 'xml')
            || str_contains($mimeType, 'svg')
            || str_contains($mimeType, 'mvg')
            || str_contains($mimeType, 'msl');
    }

    /**
     * The EXIF Orientation of a pass-through picture: 1 = upright, 2–8 =
     * mirrored / rotated, 0 = none recorded. Reads headers only.
     */
    private static function exifOrientation(string $contents, int $imageType): int
    {
        // No EXIF block of any spelling (JPEG APP1 "Exif", WebP "EXIF", PNG
        // "eXIf") — the common case for everything but camera files.
        if (!str_contains($contents, 'Exif') && !str_contains($contents, 'EXIF') && !str_contains($contents, 'eXIf')) {
            return 0;
        }

        if ($imageType === IMAGETYPE_GIF) {
            return 0;
        }

        if ($imageType === IMAGETYPE_WEBP) {
            // ImageMagick's ping does not surface a WebP's profiles.
            return self::webpOrientation($contents);
        }

        try {
            $image = new \Imagick();
            // ping = headers and profiles only, no pixel decode.
            $image->pingImageBlob($contents);
            $orientation = $image->getImageOrientation();
            $image->clear();
        } catch (\ImagickException) {
            return 0;
        }

        return $orientation;
    }

    /**
     * Orientation tag (0x0112) of the first IFD in a WebP's `EXIF` chunk.
     */
    private static function webpOrientation(string $contents): int
    {
        $length = strlen($contents);
        $offset = 12; // "RIFF" size "WEBP"

        while ($offset + 8 <= $length) {
            $chunkSize = self::uint(substr($contents, $offset + 4, 4), true);

            if (substr($contents, $offset, 4) !== 'EXIF') {
                $offset += 8 + $chunkSize + ($chunkSize % 2);

                continue;
            }

            $tiff = substr($contents, $offset + 8, min($chunkSize, 65_536));

            if (str_starts_with($tiff, "Exif\0\0")) {
                $tiff = substr($tiff, 6);
            }

            $littleEndian = str_starts_with($tiff, 'II');

            if (!$littleEndian && !str_starts_with($tiff, 'MM')) {
                return 0;
            }

            $ifd = self::uint(substr($tiff, 4, 4), $littleEndian);
            $entries = self::uint(substr($tiff, $ifd, 2), $littleEndian);

            for ($i = 0; $i < min($entries, 256); $i++) {
                $entry = substr($tiff, $ifd + 2 + $i * 12, 12);

                if (strlen($entry) === 12 && self::uint(substr($entry, 0, 2), $littleEndian) === 0x0112) {
                    $orientation = self::uint(substr($entry, 8, 2), $littleEndian);

                    return $orientation >= 1 && $orientation <= 8 ? $orientation : 0;
                }
            }

            return 0;
        }

        return 0;
    }

    private static function uint(string $bytes, bool $littleEndian): int
    {
        $format = match (strlen($bytes)) {
            2 => $littleEndian ? 'v' : 'n',
            4 => $littleEndian ? 'V' : 'N',
            default => null,
        };

        if ($format === null) {
            return 0;
        }

        $value = unpack($format, $bytes);

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
    }

    /**
     * Rotates the pixels the way the EXIF flag says and re-encodes in the
     * SAME format, dropping the metadata (the flag with it) but keeping the
     * colour profile.
     *
     * @return null|array{contents: string, extension: string, mimeType: string, width: int, height: int}
     */
    private function reorient(string $contents, string $extension, string $mimeType): null|array
    {
        try {
            $image = new \Imagick();
            $image->readImageBlob($contents);
            $image->setIteratorIndex(0);
            $image->autoOrient();

            $iccProfile = $image->getImageProfiles('icc', true)['icc'] ?? null;
            $image->stripImage();

            if (is_string($iccProfile) && $iccProfile !== '') {
                $image->profileImage('icc', $iccProfile);
            }

            // stripImage() leaves the orientation attribute alone on some builds.
            $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);

            if ($extension !== 'png') {
                $image->setImageCompressionQuality(self::REORIENT_QUALITY);
            }

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
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
}
