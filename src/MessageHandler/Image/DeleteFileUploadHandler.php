<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Image;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FileUploadNotFound;
use WBoost\Web\Message\Image\DeleteFileUpload;
use WBoost\Web\Repository\FileUploadRepository;

#[AsMessageHandler]
readonly final class DeleteFileUploadHandler
{
    public function __construct(
        private FileUploadRepository $fileUploadRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * "Delete" a gallery image = move it to the trash bin. The row and the
     * storage object BOTH survive (templates referencing the picture keep
     * rendering during the retention window), the file just detaches from its
     * folder and disappears from every consumer surface — gallery browse,
     * placeholder pick lists, fill/export validation. Recoverable via
     * {@see RestoreFileUploadHandler}; permanently removed by
     * {@see PurgeFileUploadHandler} ("Smazat ihned" or the
     * app:gallery:purge-trash cron after FileUpload::TRASH_RETENTION_DAYS).
     *
     * @throws FileUploadNotFound
     */
    public function __invoke(DeleteFileUpload $message): void
    {
        $file = $this->fileUploadRepository->get($message->fileId);

        $file->moveToTrash($this->clock->now());
    }
}
