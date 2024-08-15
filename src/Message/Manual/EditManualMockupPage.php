<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class EditManualMockupPage
{
    public function __construct(
        public UuidInterface $pageId,
        public string $name,
        /** @var array<null|UploadedFile> */
        public array $images,
    ) {
    }
}
