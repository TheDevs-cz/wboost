<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Image;

/**
 * How much picture this app is willing to DECODE.
 *
 * The 10 MB upload cap bounds the bytes, not the pixels: a 10 MB PNG of one
 * flat colour can be 30 000 × 30 000 — 900 megapixels, several gigabytes once
 * ImageMagick holds it in memory. So a decode is preceded by a look at the
 * size the file's HEADER reports ({@see pixelSize()} — no pixel is touched),
 * and anything above {@see MAX_PIXELS} is not decoded at all.
 *
 * 60 megapixels admits every print photo that fits the upload cap (a 50 MP
 * phone or camera JPEG is 8 660 × 5 773) with room to spare.
 */
final class ImageDecodeLimits
{
    public const int MAX_PIXELS = 60_000_000;

    private const int MEMORY_BYTES = 512 * 1024 * 1024;
    private const int MAP_BYTES = 1024 * 1024 * 1024;
    private const int DISK_BYTES = 2 * 1024 * 1024 * 1024;

    /**
     * The pixel size the bytes announce, read without decoding: PHP's own
     * header reader first, an ImageMagick ping (headers only) for the formats
     * PHP cannot size (HEIC, TIFF…). Text (SVG, ImageMagick's script
     * formats) is never pinged — ImageMagick would render it.
     *
     * @return null|array{int, int} [width, height]; null = not a raster either can size
     */
    public static function pixelSize(string $contents): null|array
    {
        if ($contents === '') {
            return null;
        }

        $size = @getimagesizefromstring($contents);

        if ($size !== false && $size[0] > 0 && $size[1] > 0) {
            return [$size[0], $size[1]];
        }

        if (NormalizeImageFormat::isText($contents)) {
            return null;
        }

        try {
            $image = new \Imagick();
            $image->pingImageBlob($contents);
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $image->clear();
        } catch (\ImagickException) {
            return null;
        }

        return $width > 0 && $height > 0 ? [$width, $height] : null;
    }

    public static function exceeds(int $width, int $height): bool
    {
        return $width * $height > self::MAX_PIXELS;
    }

    /**
     * Caps what ImageMagick may take for one picture: beyond the memory limit
     * the pixel cache moves to disk, beyond the disk limit the decode fails
     * (an ImagickException the callers already answer). The second line of
     * defence behind {@see MAX_PIXELS} — for decoders that render to a size no
     * header announces (Ghostscript).
     *
     * ImageMagick's limits are PROCESS-wide and stay set in a resident worker,
     * so the values are deliberately roomy for every other module: an A2
     * print page at 300 dpi is 35 MP ≈ 280 MB. No TIME limit — ImageMagick
     * counts it from process start, which would kill a long-lived worker.
     */
    public static function apply(): void
    {
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, self::MEMORY_BYTES);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, self::MAP_BYTES);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_DISK, self::DISK_BYTES);
    }
}
