<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use League\Flysystem\FilesystemException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\ImportManualImagesToGallery;
use WBoost\Web\Repository\ManualRepository;
use WBoost\Web\Services\Manual\ManualGalleryImport;

#[AsMessageHandler]
readonly final class ImportManualImagesToGalleryHandler
{
    public function __construct(
        private ManualRepository $manualRepository,
        private ManualGalleryImport $import,
    ) {
    }

    /**
     * @throws ManualNotFound
     * @throws FilesystemException
     */
    public function __invoke(ImportManualImagesToGallery $message): void
    {
        $this->import->apply($this->manualRepository->get($message->manualId));
    }
}
