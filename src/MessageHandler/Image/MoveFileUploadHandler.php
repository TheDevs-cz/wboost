<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Image;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FileDirectoryNotFound;
use WBoost\Web\Exceptions\FileUploadNotFound;
use WBoost\Web\Message\Image\MoveFileUpload;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Repository\FileUploadRepository;

#[AsMessageHandler]
readonly final class MoveFileUploadHandler
{
    public function __construct(
        private FileUploadRepository $fileUploadRepository,
        private FileDirectoryRepository $fileDirectoryRepository,
    ) {
    }

    /**
     * @throws FileUploadNotFound
     * @throws FileDirectoryNotFound
     */
    public function __invoke(MoveFileUpload $message): void
    {
        $file = $this->fileUploadRepository->get($message->fileId);

        // The bin is read-only: a trashed file leaves it via restore/purge
        // only, never via a move (which would silently un-trash it).
        if ($file->isTrashed()) {
            return;
        }

        $directory = $message->directoryId !== null
            ? $this->fileDirectoryRepository->get($message->directoryId)
            : null;

        $file->moveToDirectory($directory);
    }
}
