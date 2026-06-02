<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Image;

use Ramsey\Uuid\UuidInterface;

readonly final class DeleteFileDirectory
{
    public function __construct(
        public UuidInterface $directoryId,
    ) {
    }
}
