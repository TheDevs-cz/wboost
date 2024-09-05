<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Value\ManualType;

readonly final class EditManual
{
    public function __construct(
        public UuidInterface $manualId,
        public ManualType $type,
        public string $name,
        public null|UploadedFile $introImage,
    ) {
    }
}
