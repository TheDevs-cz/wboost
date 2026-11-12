<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\AiScan\AiScanRefusal;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * What the web process checks about an uploaded picture — **from the header
 * only; nothing is decoded here** (architecture.md §6): the type by its magic
 * bytes, the byte size, and the pixel size as the header declares it. HEIC is
 * accepted on bytes alone (its size sits in boxes this reader does not walk);
 * the scanner's intake checks it while normalising. SVG and everything else
 * is refused.
 *
 * Decoding, EXIF rotation, CMYK, 16-bit and animation are the scanner's, in
 * its own child process with a pixel limit.
 */
final class AiScanUpload
{
    /** Enough for any raster header: a JPEG's SOF can sit behind a few hundred KB of EXIF / ICC. */
    private const int HEADER_BYTES = 262144;

    /**
     * @return array{contentType: string, ext: string, bytes: int, width: null|int, height: null|int, sha256: string}
     * @throws AiScanRefused `unsupported_image`, with `reason`: type | bytes | side | pixels | small | unreadable
     */
    public static function inspect(string $path): array
    {
        $bytes = @filesize($path);

        if ($bytes === false || $bytes < 1) {
            throw self::refused('unreadable');
        }

        if ($bytes > AiScanLimits::UPLOAD_MAX_BYTES) {
            throw self::refused('bytes');
        }

        $header = @file_get_contents($path, false, null, 0, self::HEADER_BYTES);

        if ($header === false || $header === '') {
            throw self::refused('unreadable');
        }

        $type = self::sniff($header);

        if ($type === null) {
            throw self::refused('type');
        }

        $width = null;
        $height = null;

        if ($type['ext'] !== 'heic') {
            // Reads the format's header fields; no pixel is decoded.
            $size = @getimagesizefromstring($header);

            if ($size === false || $size[0] < 1 || $size[1] < 1) {
                throw self::refused('unreadable');
            }

            [$width, $height] = [$size[0], $size[1]];

            if (max($width, $height) > AiScanLimits::UPLOAD_MAX_SIDE) {
                throw self::refused('side');
            }

            if ($width * $height > AiScanLimits::UPLOAD_MAX_PIXELS) {
                throw self::refused('pixels');
            }

            if (min($width, $height) < AiScanLimits::UPLOAD_MIN_SHORT_SIDE) {
                throw self::refused('small');
            }
        }

        $sha256 = hash_file('sha256', $path);

        if ($sha256 === false) {
            throw self::refused('unreadable');
        }

        return [
            'contentType' => $type['contentType'],
            'ext' => $type['ext'],
            'bytes' => $bytes,
            'width' => $width,
            'height' => $height,
            'sha256' => $sha256,
        ];
    }

    /**
     * @return null|array{contentType: string, ext: string}
     */
    public static function sniff(string $header): null|array
    {
        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return ['contentType' => 'image/jpeg', 'ext' => 'jpg'];
        }

        if (str_starts_with($header, "\x89PNG\r\n\x1A\n")) {
            return ['contentType' => 'image/png', 'ext' => 'png'];
        }

        if (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP') {
            return ['contentType' => 'image/webp', 'ext' => 'webp'];
        }

        // ISO base media: `ftyp` box with a HEIF brand.
        if (substr($header, 4, 4) === 'ftyp' && in_array(substr($header, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'], true)) {
            return ['contentType' => 'image/heic', 'ext' => 'heic'];
        }

        return null;
    }

    private static function refused(string $reason): AiScanRefused
    {
        return new AiScanRefused(AiScanRefusal::UnsupportedImage, ['reason' => $reason]);
    }
}
