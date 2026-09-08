<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Image;

use League\Flysystem\Filesystem;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FileUploadInUse;
use WBoost\Web\Exceptions\FileUploadNotFound;
use WBoost\Web\Message\Image\PurgeFileUpload;
use WBoost\Web\Query\GetGalleryImageUsage;
use WBoost\Web\Repository\FileUploadRepository;

#[AsMessageHandler]
readonly final class PurgeFileUploadHandler
{
    public function __construct(
        private FileUploadRepository $fileUploadRepository,
        private Filesystem $filesystem,
        private GetGalleryImageUsage $galleryImageUsage,
    ) {
    }

    /**
     * Permanently delete a gallery image: drop the physical object from storage
     * AND remove the database row — the only place in the app that hard-deletes
     * gallery storage. `Filesystem::delete()` on the S3/Minio adapter is
     * idempotent (DeleteObject succeeds even when the key is already gone), so
     * a retry after a partially-applied delete is harmless.
     *
     * A picture a template still references is refused unless the purge is
     * forced: purging it is the one gallery action that damages a design for
     * good (the prod group whose every variant referenced a purged picture
     * could not even be opened for editing, 2026-09-08). The cron never
     * forces; the bin's "Smazat ihned" does, after naming the templates.
     *
     * The storage delete runs inside the command bus's doctrine_transaction, so
     * it happens just before the row removal is committed. A commit failure
     * after the object is gone would leave a row pointing at a missing file —
     * acceptable and self-healing here: the bin shows a broken thumbnail the
     * admin can simply purge again (the now-missing object is a no-op).
     *
     * @throws FileUploadNotFound
     * @throws FileUploadInUse
     */
    public function __invoke(PurgeFileUpload $message): void
    {
        $file = $this->fileUploadRepository->get($message->fileId);

        if (!$message->force) {
            $sites = $this->galleryImageUsage->forFile($file);
            if ($sites !== []) {
                throw new FileUploadInUse($file, $sites);
            }
        }

        $this->filesystem->delete($file->path);

        $this->fileUploadRepository->remove($file);
    }
}
