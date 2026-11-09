<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;

/**
 * Make sure the gallery folder `Manuál / <manual>` exists, so a manual
 * editor's gallery picker opens there and its uploads land there.
 * Idempotent (folders are found by system key).
 */
readonly final class EnsureManualGalleryFolder
{
    public function __construct(
        public UuidInterface $manualId,
    ) {
    }
}
