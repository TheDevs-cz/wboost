<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Font;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Font\DeleteFont;
use WBoost\Web\Repository\FontRepository;

#[AsMessageHandler]
readonly final class DeleteFontHandler
{
    public function __construct(
        private FontRepository $fontRepository,
        private Filesystem $filesystem,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeleteFont $message): void
    {
        $font = $this->fontRepository->get($message->fontId);
        $paths = array_map(static fn ($face): string => $face->filePath, $font->faces);

        $this->fontRepository->remove($font);

        // See DeleteFontFaceHandler: the files are orphans once the row is
        // gone; failures are logged, never surfaced.
        foreach ($paths as $path) {
            try {
                $this->filesystem->delete($path);
            } catch (FilesystemException $exception) {
                $this->logger->warning('Font file could not be deleted from storage.', [
                    'path' => $path,
                    'exception' => $exception,
                ]);
            }
        }
    }
}
