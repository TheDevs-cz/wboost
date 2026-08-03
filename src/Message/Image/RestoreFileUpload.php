<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Image;

use Ramsey\Uuid\UuidInterface;

/**
 * Restore a trashed gallery image back to the folder it was deleted from
 * (or the gallery root when that folder no longer exists).
 */
readonly final class RestoreFileUpload
{
    public function __construct(
        public UuidInterface $fileId,
    ) {
    }
}
