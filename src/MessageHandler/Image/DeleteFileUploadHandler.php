<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Image;

use League\Flysystem\Filesystem;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FileUploadNotFound;
use WBoost\Web\Message\Image\DeleteFileUpload;
use WBoost\Web\Repository\FileUploadRepository;

#[AsMessageHandler]
readonly final class DeleteFileUploadHandler
{
    public function __construct(
        private FileUploadRepository $fileUploadRepository,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * Permanently delete a gallery image: drop the physical object from storage
     * AND remove the database row. `Filesystem::delete()` on the S3/Minio
     * adapter is idempotent (DeleteObject succeeds even when the key is already
     * gone), so a retry after a partially-applied delete is harmless.
     *
     * The storage delete runs inside the command bus's doctrine_transaction, so
     * it happens just before the row removal is committed. A commit failure
     * after the object is gone would leave a row pointing at a missing file —
     * acceptable and self-healing here: the gallery shows a broken thumbnail
     * the admin can simply delete again (the now-missing object is a no-op).
     *
     * @throws FileUploadNotFound
     */
    public function __invoke(DeleteFileUpload $message): void
    {
        $file = $this->fileUploadRepository->get($message->fileId);

        $this->filesystem->delete($file->path);

        $this->fileUploadRepository->remove($file);
    }
}
