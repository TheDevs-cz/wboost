<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Font;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class AddFont
{
    public function __construct(
        public UuidInterface $projectId,
        public UploadedFile $font,
    ) {
    }
}
