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
        /** The file offered for download next to the whole page. */
        public null|UploadedFile $downloadFile = null,
        /** @var array<null|UploadedFile> Per-slot download files, aligned with $images. */
        public array $imageDownloads = [],
    ) {
    }
}
