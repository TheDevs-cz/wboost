<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Image;

use Ramsey\Uuid\UuidInterface;

readonly final class DeleteFileUpload
{
    public function __construct(
        public UuidInterface $fileId,
    ) {
    }
}
