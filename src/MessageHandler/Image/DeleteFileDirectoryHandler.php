<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Image;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FileDirectoryNotEmpty;
use WBoost\Web\Exceptions\FileDirectoryNotFound;
use WBoost\Web\Message\Image\DeleteFileDirectory;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Repository\FileUploadRepository;

#[AsMessageHandler]
readonly final class DeleteFileDirectoryHandler
{
    public function __construct(
        private FileDirectoryRepository $fileDirectoryRepository,
        private FileUploadRepository $fileUploadRepository,
    ) {
    }

    /**
     * Only empty folders may be deleted: a folder that still holds images or
     * sub-folders is refused so its contents are never silently relocated to
     * the parent (the old behaviour, which surprised users) nor cascaded away.
     * The user must empty the folder first — delete the images and remove the
     * sub-folders one by one.
     *
     * @throws FileDirectoryNotFound
     * @throws FileDirectoryNotEmpty
     */
    public function __invoke(DeleteFileDirectory $message): void
    {
        $directory = $this->fileDirectoryRepository->get($message->directoryId);

        $hasSubfolders = $this->fileDirectoryRepository->listChildren($directory->project->id, $directory->source, $directory) !== [];
        $hasFiles = $this->fileUploadRepository->listByDirectory($directory) !== [];

        if ($hasSubfolders || $hasFiles) {
            throw new FileDirectoryNotEmpty();
        }

        $this->fileDirectoryRepository->remove($directory);
    }
}
