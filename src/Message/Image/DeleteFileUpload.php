<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Image;

use Ramsey\Uuid\UuidInterface;

/**
 * Move a gallery image to the trash bin (recoverable; purged for good after
 * FileUpload::TRASH_RETENTION_DAYS). For the irreversible removal see
 * {@see PurgeFileUpload}.
 */
readonly final class DeleteFileUpload
{
    public function __construct(
        public UuidInterface $fileId,
    ) {
    }
}
