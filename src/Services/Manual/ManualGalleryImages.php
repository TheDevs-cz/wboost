<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Services\Image\ReadStoredImagePixelSize;
use WBoost\Web\Value\FileSource;

/**
 * Makes a picture a manual stores visible in the project gallery — a
 * `FileUpload` row that REFERENCES the manual's existing storage key
 * (`manuals/{manualId}/…`): no copy, no move, the manual keeps rendering
 * the very same object. The row lands in the manual's gallery folder
 * ({@see ManualGalleryFolders}).
 *
 * Idempotent by storage key: a key that already has a row in the project
 * (live or trashed) gets that row back, never a twin — so the one-off
 * import (`app:manual:import-images-to-gallery`) can be re-run and every
 * manual upload flow can call this without bookkeeping.
 *
 * Persists only — the caller's bus transaction flushes.
 */
readonly final class ManualGalleryImages
{
    public function __construct(
        private FileUploadRepository $fileUploadRepository,
        private ManualGalleryFolders $folders,
        private ReadStoredImagePixelSize $readPixelSize,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string $label human name WITHOUT extension ("Vizitky – obrázek 2");
     *                      the stored format's extension is appended
     * @param null|UuidInterface $preferredId the row id to use when free — a
     *        content image's key already carries a UUID, and a row whose id IS
     *        that UUID is resolvable from the key alone (the MCP decompiler
     *        reads gallery ids off file names)
     * @param null|array{width: int, height: int} $pixelSize when the caller
     *        already knows it (a fresh upload); otherwise read from storage
     */
    public function register(
        Manual $manual,
        string $path,
        string $label,
        null|UuidInterface $preferredId = null,
        null|array $pixelSize = null,
    ): FileUpload {
        $existing = $this->fileUploadRepository->findByProjectAndPath($manual->project->id, $path);

        if ($existing !== null) {
            return $existing;
        }

        $id = $preferredId !== null && $this->fileUploadRepository->find($preferredId) === null
            ? $preferredId
            : Uuid::uuid7();

        $pixelSize ??= $this->readPixelSize->read($path);

        $file = new FileUpload(
            $id,
            $manual->project,
            $this->clock->now(),
            FileSource::ProjectImage,
            $path,
            $this->folders->folderFor($manual),
            self::fileName($label, $path),
            $pixelSize['width'] ?? null,
            $pixelSize['height'] ?? null,
        );

        $this->fileUploadRepository->add($file);

        return $file;
    }

    /**
     * "Vizitky – obrázek 2.jpg": control characters and path separators
     * dropped, cut to the column width, the STORED format's extension.
     */
    public static function fileName(string $label, string $path): string
    {
        $label = trim(preg_replace('~[\x00-\x1F\x7F/\\\\]+~u', ' ', $label) ?? '');
        $label = $label !== '' ? $label : 'Obrázek';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return mb_substr($label, 0, 240) . ($extension !== '' ? '.' . $extension : '');
    }

    /** The UUID a key's file name carries (`…/content/{uuid}.png`), if any. */
    public static function uuidFromPath(string $path): null|UuidInterface
    {
        $name = pathinfo($path, PATHINFO_FILENAME);

        return Uuid::isValid($name) ? Uuid::fromString($name) : null;
    }
}
