<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;

/**
 * Stores a picture for a content page. The bytes are already normalised
 * (NormalizeImageFormat — HEIC & co. transcoded, SVG kept) and `path` already
 * carries the extension of those bytes: `manuals/{manualId}/content/{uuid}.{ext}`.
 * The page itself only references it once the editor saves.
 */
readonly final class UploadManualContentImage
{
    public function __construct(
        public UuidInterface $manualId,
        public string $path,
        public string $contents,
    ) {
    }
}
