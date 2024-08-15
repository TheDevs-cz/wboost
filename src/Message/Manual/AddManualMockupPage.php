<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Value\MockupPageLayout;

readonly final class AddManualMockupPage
{
    public function __construct(
        public UuidInterface $manualId,
        public string $name,
        public MockupPageLayout $layout,
        /** @var array<null|UploadedFile> */
        public array $images,
    ) {
    }
}
