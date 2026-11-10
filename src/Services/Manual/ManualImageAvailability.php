<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use Symfony\Contracts\Service\ResetInterface;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\ManualContentPageRepository;

/**
 * Whether a picture a manual shows is still a live gallery picture (content
 * pages P3). The gallery is the single source of manual pictures, so a
 * picture moved to the Koš (or a content element whose gallery row is gone)
 * no longer shows in the manual: readers see nothing there, editors a
 * stand-in that says why. Storage itself is never touched by this — the
 * purge guard keeps every key a manual references.
 *
 * - content element WITH `galleryImageId`: row gone → `missing`, trashed → `trashed`;
 * - anything else (mockup slot, legacy element): looked up by key in the
 *   manual's project — trashed → `trashed`; no row at all → available
 *   (a key the gallery never knew, e.g. one skipped by the import).
 *
 * One query per manual and request (memoised; reset between requests —
 * the FrankenPHP worker keeps services alive).
 */
final class ManualImageAvailability implements ResetInterface
{
    public const string TRASHED = 'trashed';
    public const string MISSING = 'missing';

    /** @var array<string, array{byId: array<string, FileUpload>, byPath: array<string, FileUpload>}> */
    private array $cache = [];

    public function __construct(
        private readonly FileUploadRepository $fileUploadRepository,
        private readonly ManualContentPageRepository $contentPageRepository,
    ) {
    }

    /**
     * @return null|string null = available, else TRASHED / MISSING
     */
    public function state(Manual $manual, string $path, null|string $galleryImageId = null): null|string
    {
        $rows = $this->rows($manual);

        if ($galleryImageId !== null) {
            $file = $rows['byId'][strtolower($galleryImageId)] ?? null;

            if ($file === null) {
                return self::MISSING;
            }

            return $file->isTrashed() ? self::TRASHED : null;
        }

        $file = $rows['byPath'][$path] ?? null;

        return $file !== null && $file->isTrashed() ? self::TRASHED : null;
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * @return array{byId: array<string, FileUpload>, byPath: array<string, FileUpload>}
     */
    private function rows(Manual $manual): array
    {
        $key = $manual->id->toString();

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $paths = [];
        $ids = [];

        foreach ($manual->pages as $page) {
            foreach ($page->images as $path) {
                if (is_string($path) && $path !== '') {
                    $paths[] = $path;
                }
            }
        }

        $intro = $this->contentPageRepository->introFor($manual->id);

        foreach ([...($intro !== null ? [$intro] : []), ...$this->contentPageRepository->customFor($manual->id)] as $page) {
            $pictures = $page->imageElements();

            if ($page->background !== null) {
                $pictures[] = $page->background;
            }

            foreach ($pictures as $picture) {
                $paths[] = $picture->path;

                if ($picture->galleryImageId !== null) {
                    $ids[] = $picture->galleryImageId;
                }
            }
        }

        $byId = [];
        $byPath = [];

        foreach ($this->fileUploadRepository->listByProjectIdsOrPaths($manual->project->id, array_values(array_unique($ids)), array_values(array_unique($paths))) as $file) {
            $byId[strtolower($file->id->toString())] = $file;

            // A live row wins over a trashed twin of the same key.
            if (!isset($byPath[$file->path]) || $byPath[$file->path]->isTrashed()) {
                $byPath[$file->path] = $file;
            }
        }

        return $this->cache[$key] = ['byId' => $byId, 'byPath' => $byPath];
    }
}
