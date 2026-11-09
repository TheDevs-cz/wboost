<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\InvalidManualContent;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\UploadManualContentImage;
use WBoost\Web\Repository\ManualRepository;
use WBoost\Web\Services\Manual\ManualGalleryImages;
use WBoost\Web\Value\ManualContent\ContentImageFit;
use WBoost\Web\Value\ManualContent\ImageElement;

#[AsMessageHandler]
readonly final class UploadManualContentImageHandler
{
    public function __construct(
        private Filesystem $filesystem,
        private ManualRepository $manualRepository,
        private ManualGalleryImages $galleryImages,
    ) {
    }

    /**
     * @throws InvalidManualContent
     * @throws FilesystemException
     * @throws ManualNotFound
     */
    public function __invoke(UploadManualContentImage $message): void
    {
        $image = new ImageElement('upload', $message->path, ContentImageFit::Natural);

        if (!ImageElement::isValidPath($message->path) || !$image->belongsToManual($message->manualId->toString())) {
            throw new InvalidManualContent('The picture must be stored under its manual.');
        }

        $manual = $this->manualRepository->get($message->manualId);
        $this->filesystem->write($message->path, $message->contents);

        $label = pathinfo(basename(str_replace('\\', '/', $message->originalName)), PATHINFO_FILENAME);
        $this->galleryImages->register(
            $manual,
            $message->path,
            $label !== '' ? $label : 'Obrázek',
            ManualGalleryImages::uuidFromPath($message->path),
            $message->width !== null && $message->height !== null ? ['width' => $message->width, 'height' => $message->height] : null,
        );
    }
}
