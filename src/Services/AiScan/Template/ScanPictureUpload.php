<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Template;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Message\Image\UploadFile;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Value\FileSource;

/**
 * Pictures uploaded for a proposal's places: ordinary gallery files, stored
 * by the gallery's own upload command ({@see UploadFile} — HEIC becomes a
 * JPEG there, the pixel size is recorded) in the folder `AI sken / {název}`.
 *
 * The limits are the gallery's: **10 MB decimal** per file
 * (docs/architecture/gallery-and-uploads.md) and pictures only.
 */
final readonly class ScanPictureUpload
{
    public const int MAX_BYTES = 10_000_000;
    public const int MAX_FILES = 30;
    private const array MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif', 'image/svg+xml'];

    public function __construct(
        private MessageBusInterface $bus,
        private ProvideIdentity $provideIdentity,
        private ScanGalleryFolders $folders,
        private ScanProposal $proposal,
        private ScanUploads $uploads,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Why these files cannot be taken, or null. `field` says which rule.
     *
     * @param list<mixed> $files
     * @return null|array{field: string, file?: string}
     */
    public function refusal(array $files, bool $single): null|array
    {
        if ($files === [] || count($files) > self::MAX_FILES || ($single && count($files) !== 1)) {
            return ['field' => 'pictures'];
        }

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                return ['field' => 'pictures'];
            }

            if ($file->getSize() > self::MAX_BYTES) {
                return ['field' => 'size', 'file' => $file->getClientOriginalName()];
            }

            if (!in_array($file->getMimeType(), self::MIME_TYPES, true)) {
                return ['field' => 'type', 'file' => $file->getClientOriginalName()];
            }
        }

        return null;
    }

    /**
     * @param list<UploadedFile> $files
     * @return list<string> the gallery ids, in the order of the files
     */
    public function store(AiScan $scan, array $files): array
    {
        $folder = $this->folders->folderFor($scan, $this->proposal->name($scan));
        $this->entityManager->flush();
        $ids = [];

        foreach ($files as $file) {
            $id = $this->provideIdentity->next();
            $this->bus->dispatch(new UploadFile($id, $scan->project->id, FileSource::ProjectImage, $file, $folder->id));
            $ids[] = $id->toString();
        }

        return $ids;
    }

    /**
     * Files stored by a request whose fix was refused go to the bin.
     *
     * @param list<string> $fileIds
     */
    public function discard(AiScan $scan, array $fileIds): void
    {
        $this->uploads->trash(['uploads' => $fileIds], $scan->project->id->toString());
        $this->entityManager->flush();
    }
}
