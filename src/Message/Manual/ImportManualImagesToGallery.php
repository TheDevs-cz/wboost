<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;

/**
 * Content pages P3: register one manual's pictures in the project gallery
 * (`Manuál / <manual>`) over their existing storage keys — see
 * {@see \WBoost\Web\Services\Manual\ManualGalleryImport}. Idempotent.
 */
readonly final class ImportManualImagesToGallery
{
    public function __construct(
        public UuidInterface $manualId,
    ) {
    }
}
