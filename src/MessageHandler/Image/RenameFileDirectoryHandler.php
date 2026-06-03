<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Image;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FileDirectoryNotFound;
use WBoost\Web\Message\Image\RenameFileDirectory;
use WBoost\Web\Repository\FileDirectoryRepository;

#[AsMessageHandler]
readonly final class RenameFileDirectoryHandler
{
    public function __construct(
        private FileDirectoryRepository $fileDirectoryRepository,
    ) {
    }

    /**
     * @throws FileDirectoryNotFound
     */
    public function __invoke(RenameFileDirectory $message): void
    {
        $directory = $this->fileDirectoryRepository->get($message->directoryId);
        $directory->rename($message->name);
    }
}
