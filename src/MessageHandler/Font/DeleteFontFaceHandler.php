<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Font;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FontNotFound;
use WBoost\Web\Message\Font\DeleteFontFace;
use WBoost\Web\Repository\FontRepository;

#[AsMessageHandler]
readonly final class DeleteFontFaceHandler
{
    public function __construct(
        private FontRepository $fontRepository,
        private Filesystem $filesystem,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws FontNotFound
     */
    public function __invoke(DeleteFontFace $message): void
    {
        $font = $this->fontRepository->get($message->fontId);

        $paths = [];
        foreach ($font->faces as $face) {
            if ($face->name === $message->fontFaceName) {
                $paths[] = $face->filePath;
            }
        }

        $font->removeFontFace($message->fontFaceName);

        // The row is what the app reads; the file is only ever reached
        // through it, so a face that is gone from the row is an orphan in the
        // bucket. A storage failure is logged, never surfaced — the face is
        // already removed and a leaked file beats a failed request.
        foreach ($paths as $path) {
            $this->deleteFile($path);
        }
    }

    private function deleteFile(string $path): void
    {
        try {
            $this->filesystem->delete($path);
        } catch (FilesystemException $exception) {
            $this->logger->warning('Font face file could not be deleted from storage.', [
                'path' => $path,
                'exception' => $exception,
            ]);
        }
    }
}
