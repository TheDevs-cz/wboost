<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Image;

use WBoost\Web\Value\RenderImageFormat;

/**
 * Shrinks rendered picture bytes to fit a channel that pays for every pixel.
 *
 * The renderer always draws a variant at its DESIGNED size — Gotenberg is told
 * the canvas width/height and clips to it — so an A4 print variant comes back
 * at 2480 × 3508 (210 × 297 mm at {@see \WBoost\Web\Value\DimensionUnit::PRINT_DPI}).
 * That is exactly right for an export and exactly wrong for the MCP
 * `render_variant` preview, whose image travels base64-encoded (+33 %) inside a
 * JSON-RPC body and then sits in a chat client's context window for the rest of
 * the conversation. Hence a bound on the long edge rather than on the byte
 * count: an agent iterating on wording needs to SEE the layout, not to read the
 * kerning.
 *
 * ## Cheap when there is nothing to do
 *
 * The size is read from the header ({@see getimagesizefromstring()}) before
 * ImageMagick is ever constructed, so a picture already within the bound — every
 * Instagram preset variant is 1080 px, comfortably under a 1200 px cap — is
 * returned byte-for-byte with no decode at all. Only a genuinely oversized
 * render pays for a resize.
 *
 * ## Failure returns the picture, never an error
 *
 * Every failure path (unreadable header, an ImageMagick that cannot decode the
 * blob, an empty encode) hands the ORIGINAL bytes back. A caller asked for a
 * preview; a large preview beats no preview, and the reported width/height then
 * describe whatever was actually measured, so the answer stays honest about
 * what it is returning.
 */
readonly final class DownscaleImage
{
    /**
     * Re-encode quality for the resized picture.
     *
     * Note this is a REAL knob here, unlike on the Gotenberg render path where
     * {@see RenderImageFormat} documents that WebP quality is ignored — that
     * limitation belongs to Chromium's screenshot encoder, not to WebP. 82 is
     * the usual "no visible artefacts on flat design work" point.
     */
    private const int QUALITY = 82;

    /**
     * Scales `$contents` down, preserving its aspect ratio, until neither side
     * exceeds `$maxLongEdge`. Never scales UP — a picture already inside the
     * bound is passed through untouched.
     *
     * `$format` is the format to re-encode into and MUST be the format the
     * caller is going to declare for the bytes, so the announced mime type and
     * the actual bytes cannot drift apart. Alpha survives: the resize keeps the
     * channel, which matters because a layer-mode variant with no background
     * renders transparent on purpose.
     *
     * @param int $maxLongEdge Bound for the longer side, in pixels.
     *
     * @return array{contents: string, width: null|int, height: null|int, downscaled: bool}
     *   `width`/`height` describe the RETURNED bytes, or null when they could
     *   not be measured at all.
     */
    public function toLongEdge(string $contents, int $maxLongEdge, RenderImageFormat $format): array
    {
        // Suppressed: bytes this cannot size are an expected outcome (a
        // truncated render, a format PHP does not know), answered by passing
        // them through rather than by a warning in the log.
        $size = @getimagesizefromstring($contents);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return ['contents' => $contents, 'width' => null, 'height' => null, 'downscaled' => false];
        }

        [$width, $height] = $size;
        $longEdge = max($width, $height);

        if ($maxLongEdge < 1 || $longEdge <= $maxLongEdge) {
            return ['contents' => $contents, 'width' => $width, 'height' => $height, 'downscaled' => false];
        }

        $scale = $maxLongEdge / $longEdge;

        try {
            $image = new \Imagick();
            $image->readImageBlob($contents);
            // A WebP/PNG produced by the renderer is single-frame, but reading
            // a blob leaves the iterator wherever the decoder put it; pin it.
            $image->setIteratorIndex(0);
            $image->resizeImage(
                max(1, (int) round($width * $scale)),
                max(1, (int) round($height * $scale)),
                \Imagick::FILTER_LANCZOS,
                1,
            );
            // Nothing downstream reads metadata off a preview, and a colour
            // profile is a few KB of the budget this class exists to defend.
            $image->stripImage();
            $image->setImageFormat($format->value);
            $image->setImageCompressionQuality(self::QUALITY);

            // getImageBlob(), not getImagesBlob(): the current frame only.
            $bytes = $image->getImageBlob();
            $resultWidth = $image->getImageWidth();
            $resultHeight = $image->getImageHeight();

            $image->clear();
        } catch (\ImagickException | \ImagickPixelException) {
            return ['contents' => $contents, 'width' => $width, 'height' => $height, 'downscaled' => false];
        }

        if ($bytes === '' || $resultWidth < 1 || $resultHeight < 1) {
            return ['contents' => $contents, 'width' => $width, 'height' => $height, 'downscaled' => false];
        }

        return [
            'contents' => $bytes,
            'width' => $resultWidth,
            'height' => $resultHeight,
            'downscaled' => true,
        ];
    }
}
