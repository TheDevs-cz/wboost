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
 * extension describing its bytes. wboost deliberately does not keep the name a
 * file was uploaded under (the upload handler re-encodes what it must and names
 * the object after the row), so this says what FORMAT the picture is, and is
 * not a caption worth showing to a user.
 *
 * `width` / `height` are the picture's own pixel size, read from the file. They
 * are null for an SVG — a vector has no pixel size, it scales to whatever box
 * it is placed in — and for a file whose bytes could not be read. Null is
 * reported rather than guessed: a wrong aspect ratio silently mis-crops every
 * placement made from it.
 */
readonly final class GalleryImageResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public null|int $width,
        public null|int $height,
    ) {
    }
}
