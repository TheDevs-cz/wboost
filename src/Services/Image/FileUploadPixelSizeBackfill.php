<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Image;

use Doctrine\ORM\EntityManagerInterface;
use WBoost\Web\Entity\FileUpload;

/**
 * Records the pixel size on gallery rows that predate it being stored at
 * upload time (before 2026-09 nothing in the database knew how big a picture
 * was — the gallery could not tell two similar images of different sizes
 * apart, and the MCP gallery listing re-read every file header on every call).
 *
 * Two callers, one rule: a row with no size, that is not an SVG, gets its
 * header read ONCE and the result persisted; a read that yields nothing (the
 * object is gone, the bytes do not decode) leaves the row as it was and is
 * retried next time it is listed — that is rare, and a cheap failed read is
 * preferable to a marker column just to remember the failure.
 *
 * - the gallery listings call {@see backfill()} on the folder they are about
 *   to render, so old uploads heal progressively as folders are opened;
 * - `app:gallery:backfill-image-size` sweeps every remaining row in one go.
 */
readonly final class FileUploadPixelSizeBackfill
{
    public function __construct(
        private ReadStoredImagePixelSize $readStoredImagePixelSize,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Fills the missing sizes in place and flushes once if anything changed.
     *
     * @param iterable<FileUpload> $files
     *
     * @return int how many rows gained a size
     */
    public function backfill(iterable $files): int
    {
        $filled = 0;

        foreach ($files as $file) {
            if ($file->hasPixelSize() || $file->isSvg()) {
                continue;
            }

            $size = $this->readStoredImagePixelSize->read($file->path);

            if ($size === null) {
                continue;
            }

            $file->recordPixelSize($size['width'], $size['height']);
            $filled++;
        }

        if ($filled > 0) {
            $this->entityManager->flush();
        }

        return $filled;
    }
}
