<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Image;

use Ramsey\Uuid\UuidInterface;

readonly final class RenameFileDirectory
{
    public function __construct(
        public UuidInterface $directoryId,
        public string $name,
    ) {
    }
}
