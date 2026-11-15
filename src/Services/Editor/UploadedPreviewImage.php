<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Editor;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The thumbnail an editor save uploads as a binary file (`imagePreviewFile`)
 * — since 2026-11-15 instead of a base64 data URI in a text field, a third
 * less to send (Sentry WEB-4X: saves on slow uplinks).
 *
 * The bytes go straight into the `.png` storage key the variant thumbnail
 * lives under, so only a PNG is taken. Anything else — no file, a failed
 * upload, an empty or oversized file, not a PNG — reads as '' and the canvas
 * handler keeps the stored thumbnail: a thumbnail is never worth failing a
 * design save over.
 */
final class UploadedPreviewImage
{
    /** A 1000 px wide thumbnail is a few hundred kB; this is generous headroom. */
    public const int MAX_BYTES = 10_000_000;

    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    public static function bytes(mixed $file): string
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return '';
        }

        $size = $file->getSize();

        if ($size <= 0 || $size > self::MAX_BYTES) {
            return '';
        }

        $bytes = file_get_contents($file->getPathname());

        if ($bytes === false || !str_starts_with($bytes, self::PNG_SIGNATURE)) {
            return '';
        }

        return $bytes;
    }
}
