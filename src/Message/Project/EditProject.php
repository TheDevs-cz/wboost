<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Project;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class EditProject
{
    public function __construct(
        public UuidInterface $projectId,
        public string $name,
        public null|UploadedFile $icon = null,
        public bool $removeIcon = false,
    ) {
    }
}
