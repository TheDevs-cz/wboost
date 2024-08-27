<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Image;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Value\FileSource;

readonly final class UploadFile
{
    public function __construct(
        public UuidInterface $fileId,
        public UuidInterface $projectId,
        public FileSource $source,
        public UploadedFile $file,
    ) {
    }
}
