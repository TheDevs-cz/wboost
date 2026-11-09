<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\InvalidManualContent;
use WBoost\Web\Message\Manual\UploadManualContentImage;
use WBoost\Web\Value\ManualContent\ContentImageFit;
use WBoost\Web\Value\ManualContent\ImageElement;

#[AsMessageHandler]
readonly final class UploadManualContentImageHandler
{
    public function __construct(
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws InvalidManualContent
     * @throws FilesystemException
     */
    public function __invoke(UploadManualContentImage $message): void
    {
        $image = new ImageElement('upload', $message->path, ContentImageFit::Natural);

        if (!ImageElement::isValidPath($message->path) || !$image->belongsToManual($message->manualId->toString())) {
            throw new InvalidManualContent('The picture must be stored under its manual.');
        }

        $this->filesystem->write($message->path, $message->contents);
    }
}
