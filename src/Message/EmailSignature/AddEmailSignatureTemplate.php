<?php

declare(strict_types=1);

namespace WBoost\Web\Message\EmailSignature;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class AddEmailSignatureTemplate
{
    public function __construct(
        public UuidInterface $templateId,
        public UuidInterface $projectId,
        public string $name,
        public null|UploadedFile $backgroundImage,
    ) {
    }
}
