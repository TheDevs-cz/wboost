<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Entity\ManualMockupPage;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\ManualContentPageRepository;
use WBoost\Web\Value\ManualGallery\ManualGalleryImportItem;

/**
 * Content pages P3: every picture a manual shows (content page image
 * elements — the intro included — and mockup page slots) becomes a gallery
 * picture in `Manuál / <manual>`. The gallery rows REFERENCE the existing
 * keys: nothing is copied, nothing is deleted, the manual renders the very
 * same objects afterwards.
 *
 * {@see plan()} is read-only (the dry run); {@see apply()} writes and is
 * idempotent: keys that already have a row are linked, never duplicated.
 * A key missing from storage is skipped (reported as `missing`) — no row is
 * created for a picture that cannot be shown. Attachments (mockup
 * `download_file` / `image_downloads`) are not pictures and stay out.
 */
readonly final class ManualGalleryImport
{
    public function __construct(
        private ManualContentPageRepository $contentPageRepository,
        private FileUploadRepository $fileUploadRepository,
        private ManualGalleryImages $galleryImages,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @return list<ManualGalleryImportItem>
     * @throws FilesystemException
     */
    public function plan(Manual $manual): array
    {
        $items = [];
        /** @var array<string, string> $fileIdByPath */
        $fileIdByPath = [];
        /** @var array<string, bool> $plannedPaths */
        $plannedPaths = [];

        foreach ($this->contentPages($manual) as $page) {
            foreach ($page->imageElements() as $index => $element) {
                $fileId = $this->existingFileId($manual, $element->path, $fileIdByPath);

                if ($fileId !== null) {
                    $action = $element->galleryImageId === $fileId ? ManualGalleryImportItem::DONE : ManualGalleryImportItem::LINK;
                } elseif (isset($plannedPaths[$element->path])) {
                    $action = ManualGalleryImportItem::LINK;
                } else {
                    $action = $this->filesystem->fileExists($element->path) ? ManualGalleryImportItem::CREATE : ManualGalleryImportItem::MISSING;
                }

                if ($action === ManualGalleryImportItem::CREATE) {
                    $plannedPaths[$element->path] = true;
                }

                $items[] = new ManualGalleryImportItem(
                    'content',
                    $page->id->toString(),
                    $page->name,
                    $element->id,
                    $element->path,
                    sprintf('%s – obrázek %d', $page->name, $index + 1),
                    $action,
                    $fileId,
                );
            }
        }

        foreach ($this->mockupPages($manual) as $page) {
            foreach ($page->images as $slot => $path) {
                if (!is_string($path) || $path === '') {
                    continue;
                }

                $fileId = $this->existingFileId($manual, $path, $fileIdByPath);

                if ($fileId !== null || isset($plannedPaths[$path])) {
                    // A mockup slot stores the key only — an existing row is all
                    // it needs.
                    $action = ManualGalleryImportItem::DONE;
                } else {
                    $action = $this->filesystem->fileExists($path) ? ManualGalleryImportItem::CREATE : ManualGalleryImportItem::MISSING;
                }

                if ($action === ManualGalleryImportItem::CREATE) {
                    $plannedPaths[$path] = true;
                }

                $items[] = new ManualGalleryImportItem(
                    'mockup',
                    $page->id->toString(),
                    $page->name,
                    (string) ($slot + 1),
                    $path,
                    sprintf('%s – obrázek %d', $page->name, $slot + 1),
                    $action,
                    $fileId,
                );
            }
        }

        return $items;
    }

    /**
     * Creates the missing rows and links the content image elements. Returns
     * the plan it executed.
     *
     * @return list<ManualGalleryImportItem>
     * @throws FilesystemException
     */
    public function apply(Manual $manual): array
    {
        $items = $this->plan($manual);
        /** @var array<string, string> $fileIdByPath */
        $fileIdByPath = [];

        foreach ($items as $item) {
            if ($item->action === ManualGalleryImportItem::MISSING) {
                continue;
            }

            if ($item->fileId !== null) {
                $fileIdByPath[$item->path] = $item->fileId;
                continue;
            }

            if (!isset($fileIdByPath[$item->path])) {
                $file = $this->galleryImages->register(
                    $manual,
                    $item->path,
                    $item->label,
                    ManualGalleryImages::uuidFromPath($item->path),
                );
                $fileIdByPath[$item->path] = $file->id->toString();
            }
        }

        foreach ($this->contentPages($manual) as $page) {
            $page->linkGalleryImages($fileIdByPath);
        }

        return $items;
    }

    /**
     * @param array<string, string> $cache
     */
    private function existingFileId(Manual $manual, string $path, array &$cache): null|string
    {
        if (!array_key_exists($path, $cache)) {
            $cache[$path] = $this->fileUploadRepository->findByProjectAndPath($manual->project->id, $path)?->id->toString() ?? '';
        }

        return $cache[$path] !== '' ? $cache[$path] : null;
    }

    /**
     * Intro first, then the custom pages in their order.
     *
     * @return list<ManualContentPage>
     */
    private function contentPages(Manual $manual): array
    {
        $intro = $this->contentPageRepository->introFor($manual->id);

        return [...($intro !== null ? [$intro] : []), ...$this->contentPageRepository->customFor($manual->id)];
    }

    /**
     * @return list<ManualMockupPage>
     */
    private function mockupPages(Manual $manual): array
    {
        $pages = array_values($manual->pages->toArray());
        usort($pages, static fn (ManualMockupPage $a, ManualMockupPage $b): int => [$a->position, $a->createdAt] <=> [$b->position, $b->createdAt]);

        return $pages;
    }
}
