<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * One picture in a project's image gallery.
 *
 * `id` is the asset id every other surface accepts — the export API's `images`
 * map, an image placeholder's fill, a design document's background — so it is
 * the field an agent carries forward. `url` is the same public URL the web
 * gallery renders and is for showing a human, not for fetching bytes back into
 * a tool call.
 *
 * `name` is the STORED file name, which is this image's own id plus the
 * extension describing its bytes (the upload handler re-encodes what it must
 * and names the object after the row), so it says what FORMAT the picture is.
 * `originalName` is the name the file was uploaded under — the caption a user
 * recognises a picture by, and what the web gallery shows on its tiles. It is
 * null for uploads from before it was recorded (2026-09).
 *
 * `width` / `height` are the picture's own pixel size, recorded at upload (or
 * read from the file once for older rows). They are null for an SVG — a vector
 * has no pixel size, it scales to whatever box it is placed in — and for a file
 * whose bytes could not be read. Null is reported rather than guessed: a wrong
 * aspect ratio silently mis-crops every placement made from it.
 */
readonly final class GalleryImageResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public null|string $originalName,
        public string $url,
        public null|int $width,
        public null|int $height,
    ) {
    }
}
