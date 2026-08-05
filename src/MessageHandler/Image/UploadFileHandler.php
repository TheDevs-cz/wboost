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
use WBoost\Web\Services\Image\NormalizeImageFormat;

#[AsMessageHandler]
readonly final class UploadFileHandler
{
    public function __construct(
        private FileUploadRepository $fileUploadRepository,
        private FileDirectoryRepository $fileDirectoryRepository,
        private ProjectRepository $projectRepository,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private NormalizeImageFormat $normalizeImageFormat,
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
        $contents = $file->getContent();

        // The single chokepoint every gallery upload passes through (the
        // project gallery form AND the placeholder upload endpoints), so it is
        // where a picture is made readable by the rest of the app: a HEIC
        // straight off an iPhone becomes a JPEG here, and the extension always
        // describes the bytes rather than the name the client happened to send.
        // A non-raster upload (SVG above all) is stored untouched.
        $normalized = $this->normalizeImageFormat->normalize($contents);

        if ($normalized !== null) {
            $contents = $normalized['contents'];
            $extension = $normalized['extension'];
        } else {
            $extension = strtolower($file->getClientOriginalExtension());
        }

        $filePath = "file-upload/{$project->id}/{$message->fileId}.$extension";
        $this->filesystem->write($filePath, $contents);

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
