<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Editor;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Exceptions\FileUploadNotFound;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Value\StoredBackgroundImage;

/**
 * Resolves a form-picked gallery file id into the background descriptor the
 * template handlers seed layers from. The add/edit template + group forms
 * pick backgrounds from the PROJECT GALLERY (a picked or freshly uploaded
 * background lives there, visible and reusable) and submit only the
 * FileUpload id; the variant then REFERENCES the gallery path — the same
 * shape the editor's "Pozadí" pick has always written, so no bytes are
 * copied and the storage scan sees an ordinary reference.
 *
 * Guarded: the file must belong to the given project and must not sit in the
 * trash. An id failing any check resolves to null and the caller proceeds
 * without a background (the benign degrade — the picker only offers valid
 * files, so this is reached by a stale form, a purged file, or tampering).
 */
readonly final class ResolveGalleryBackground
{
    public function __construct(
        private FileUploadRepository $fileUploadRepository,
        private Filesystem $filesystem,
    ) {
    }

    public function resolve(null|string $fileId, UuidInterface $projectId): null|StoredBackgroundImage
    {
        if ($fileId === null || $fileId === '' || !Uuid::isValid($fileId)) {
            return null;
        }

        try {
            $file = $this->fileUploadRepository->get(Uuid::fromString($fileId));
        } catch (FileUploadNotFound) {
            return null;
        }

        if (!$file->project->id->equals($projectId) || $file->isTrashed()) {
            return null;
        }

        try {
            $bytes = $this->filesystem->read($file->path);
        } catch (FilesystemException) {
            return null;
        }

        $size = getimagesizefromstring($bytes);

        return new StoredBackgroundImage(
            $file->path,
            is_array($size) ? $size[0] : null,
            is_array($size) ? $size[1] : null,
        );
    }
}
