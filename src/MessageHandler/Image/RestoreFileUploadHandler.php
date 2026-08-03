<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Image;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FileUploadNotFound;
use WBoost\Web\Message\Image\RestoreFileUpload;
use WBoost\Web\Repository\FileUploadRepository;

#[AsMessageHandler]
readonly final class RestoreFileUploadHandler
{
    public function __construct(
        private FileUploadRepository $fileUploadRepository,
    ) {
    }

    /**
     * Put a trashed image back where it came from — or at the gallery root
     * when its original folder was deleted in the meantime (the entity's
     * restoreDirectory FK is SET NULL). A no-op for images not in the bin.
     *
     * @throws FileUploadNotFound
     */
    public function __invoke(RestoreFileUpload $message): void
    {
        $file = $this->fileUploadRepository->get($message->fileId);

        $file->restoreFromTrash();
    }
}
