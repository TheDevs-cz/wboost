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
        /** @var array<bool> Slot indexes flagged true get their existing image removed (unless a new upload replaces it). */
        public array $removeImages = [],
        /** The file offered for download next to the whole page. */
        public null|UploadedFile $downloadFile = null,
        /** True drops the page's existing download file (unless a new upload replaces it). */
        public bool $removeDownloadFile = false,
        /** @var array<null|UploadedFile> Per-slot download files, aligned with $images. */
        public array $imageDownloads = [],
        /** @var array<bool> Slot indexes flagged true get their existing download file removed. */
        public array $removeImageDownloads = [],
    ) {
    }
}
