<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Image;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
            $width = $normalized['width'];
            $height = $normalized['height'];
        } else {
            $extension = strtolower($file->getClientOriginalExtension());
            // An SVG (the only non-raster that gets here) has no pixel size.
            $width = null;
            $height = null;
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
            self::originalName($file),
            $width,
            $height,
        );

        $this->fileUploadRepository->add($image);
    }

    /**
     * The name the client uploaded under, kept as the human label of the
     * picture. Browsers already send a bare file name, but the value is
     * client-controlled: path separators and control characters are dropped
     * and it is cut to the column width. Empty → null (nothing to show).
     */
    private static function originalName(UploadedFile $file): null|string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return mb_substr($name, 0, 255);
    }
}
