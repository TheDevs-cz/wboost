<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Image;

use Ramsey\Uuid\UuidInterface;

/**
 * Relabel a gallery picture — `name` is the full caption including its
 * extension ("skore-0.png"); the stored object is not touched.
 */
readonly final class RenameFileUpload
{
    public function __construct(
        public UuidInterface $fileId,
        public string $name,
    ) {
    }
}
