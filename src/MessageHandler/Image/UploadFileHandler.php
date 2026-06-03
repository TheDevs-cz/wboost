<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Image;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Exceptions\FileDirectoryNotFound;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\Image\UploadFile;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\ProjectRepository;

#[AsMessageHandler]
readonly final class UploadFileHandler
{
    public function __construct(
        private FileUploadRepository $fileUploadRepository,
        private FileDirectoryRepository $fileDirectoryRepository,
        private ProjectRepository $projectRepository,
        private Filesystem $filesystem,
        private ClockInterface $clock,
    )
    {
    }

    /**
     * @throws ProjectNotFound
     * @throws FileDirectoryNotFound
     */
    public function __invoke(UploadFile $message): void
    {
        $project = $this->projectRepository->get($message->projectId);

        $directory = $message->directoryId !== null
            ? $this->fileDirectoryRepository->get($message->directoryId)
            : null;

        $file = $message->file;

        $extension = $file->getClientOriginalExtension();
        $filePath = "file-upload/{$project->id}/{$message->fileId}.$extension";
        $this->filesystem->write($filePath, $file->getContent());

        $image = new FileUpload(
            $message->fileId,
            $project,
            $this->clock->now(),
            $message->source,
            $filePath,
            $directory,
        );

        $this->fileUploadRepository->add($image);
    }
}
