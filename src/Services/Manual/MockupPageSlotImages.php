<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Repository\FileUploadRepository;

/**
 * What a mockup page slot ends up showing when the editor sent something
 * new for it (content pages P3): a fresh UPLOAD wins (stored under the
 * page as before and registered in the gallery folder `Manuál / <manual>`),
 * else a GALLERY PICK (the row must be a live picture of the manual's own
 * project — a typed id must not pull another client's file into a public
 * manual; anything else is ignored). Null = nothing new for the slot.
 */
readonly final class MockupPageSlotImages
{
    public function __construct(
        private Filesystem $filesystem,
        private FileUploadRepository $fileUploadRepository,
        private ManualGalleryImages $galleryImages,
    ) {
    }

    /**
     * @throws FilesystemException
     */
    public function resolve(
        Manual $manual,
        UuidInterface $pageId,
        string $pageName,
        int $index,
        null|UploadedFile $upload,
        null|string $galleryImageId,
        int $timestamp,
    ): null|string {
        if ($upload !== null) {
            $imageNumber = $index + 1;
            $extension = $upload->guessExtension();
            $path = "manuals/$manual->id/pages/$pageId/image-$imageNumber-$timestamp.$extension";

            $this->filesystem->write($path, $upload->getContent());

            $label = pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME);
            $this->galleryImages->register($manual, $path, $label !== '' ? $label : sprintf('%s – obrázek %d', $pageName, $imageNumber));

            return $path;
        }

        if ($galleryImageId === null || !Uuid::isValid($galleryImageId)) {
            return null;
        }

        $file = $this->fileUploadRepository->find(Uuid::fromString($galleryImageId));

        if ($file === null || $file->isTrashed() || !$file->project->id->equals($manual->project->id)) {
            return null;
        }

        return $file->path;
    }
}
