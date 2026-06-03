<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Image;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
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
     * Deleting a folder never discards its contents: every child folder and
     * every file inside it is lifted up to the deleted folder's parent (the
     * gallery root when the deleted folder was top-level) before the folder
     * row itself is removed.
     *
     * @throws FileDirectoryNotFound
     */
    public function __invoke(DeleteFileDirectory $message): void
    {
        $directory = $this->fileDirectoryRepository->get($message->directoryId);
        $parent = $directory->parent;

        foreach ($this->fileDirectoryRepository->listChildren($directory->project->id, $directory->source, $directory) as $child) {
            $child->moveUnder($parent);
        }

        foreach ($this->fileUploadRepository->listByDirectory($directory) as $file) {
            $file->moveToDirectory($parent);
        }

        $this->fileDirectoryRepository->remove($directory);
    }
}
