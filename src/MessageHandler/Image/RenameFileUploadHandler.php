<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Image;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FileUploadNotFound;
use WBoost\Web\Message\Image\RenameFileUpload;
use WBoost\Web\Repository\FileUploadRepository;

#[AsMessageHandler]
readonly final class RenameFileUploadHandler
{
    public function __construct(
        private FileUploadRepository $fileUploadRepository,
    ) {
    }

    /**
     * @throws FileUploadNotFound
     */
    public function __invoke(RenameFileUpload $message): void
    {
        $file = $this->fileUploadRepository->get($message->fileId);

        // The bin is read-only, like for a move.
        if ($file->isTrashed()) {
            return;
        }

        $file->rename($message->name);
    }
}
