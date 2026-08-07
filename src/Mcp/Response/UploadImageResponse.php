<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * The reply of the `upload_image` MCP tool — the picture that now exists in the
 * project's gallery.
 *
 * The shape deliberately mirrors {@see GalleryImageResponse}, because the two
 * describe the SAME thing seen a moment apart: `imageId` is the identifier
 * `list_gallery` will report for this row from now on, and the identifier every
 * other surface accepts (an image placeholder's fill in `render_variant` /
 * `export_variant`, a design document's `asset`). An agent that uploads and
 * then places a picture never has to translate between two vocabularies.
 *
 * `url` is the public URL of the stored object — for showing a human, not for
 * fetching the bytes back into a tool call.
 *
 * `width` / `height` describe the bytes that were STORED, not the bytes that
 * were sent: an upload is normalised on the way in (a HEIC photo becomes a
 * JPEG, and a phone's EXIF rotation flag is baked into the pixels), so the size
 * reported here is the one every later consumer — the fill page, the compiler's
 * fit math, the export render — will read off the file. Both are null for an
 * SVG, which is a vector and has no pixel size at all; that is the same null
 * `list_gallery` and the design compiler report, and it means "scale me to
 * whatever box you place me in", never "unknown".
 */
readonly final class UploadImageResponse
{
    public function __construct(
        public string $imageId,
        public string $url,
        public null|int $width,
        public null|int $height,
    ) {
    }
}
