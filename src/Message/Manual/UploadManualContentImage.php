<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;

/**
 * Stores a picture for a content page. The bytes are already normalised
 * (NormalizeImageFormat — HEIC & co. transcoded, SVG kept) and `path` already
 * carries the extension of those bytes: `manuals/{manualId}/content/{uuid}.{ext}`.
 * The page itself only references it once the editor saves.
 *
 * Since content pages P3 the picture is ALSO registered in the project
 * gallery (folder `Manuál / <manual>`) over the same key, the row id being
 * the key's UUID — so the editor can store `galleryImageId` right away.
 */
readonly final class UploadManualContentImage
{
    public function __construct(
        public UuidInterface $manualId,
        public string $path,
        public string $contents,
        /** The client file name — the gallery tile's label. */
        public string $originalName = '',
        public null|int $width = null,
        public null|int $height = null,
    ) {
    }
}
