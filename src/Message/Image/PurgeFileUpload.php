<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Image;

use Ramsey\Uuid\UuidInterface;

/**
 * PERMANENTLY delete a gallery image: the storage object AND the database row.
 * Irreversible. Dispatched by the bin's "Smazat ihned" action and by the
 * app:gallery:purge-trash cron for bin entries past the retention window.
 */
readonly final class PurgeFileUpload
{
    public function __construct(
        public UuidInterface $fileId,
    ) {
    }
}
