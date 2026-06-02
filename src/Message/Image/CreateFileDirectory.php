<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Image;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\FileSource;

readonly final class CreateFileDirectory
{
    public function __construct(
        public UuidInterface $directoryId,
        public UuidInterface $projectId,
        public FileSource $source,
        public null|UuidInterface $parentId,
        public string $name,
    ) {
    }
}
