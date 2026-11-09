<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualGallery;

/**
 * One manual picture in the gallery import plan
 * (`app:manual:import-images-to-gallery`).
 *
 * - `create`  — no gallery row yet: one is created over the existing key;
 * - `link`    — a row exists, but the content image element does not point at
 *               it yet (`galleryImageId` gets set);
 * - `done`    — already imported (row exists, element linked) — nothing to do;
 * - `missing` — the key is not in storage: skipped and reported, nothing is
 *               written (a broken picture stays exactly as broken as it was).
 */
readonly final class ManualGalleryImportItem
{
    public const string CREATE = 'create';
    public const string LINK = 'link';
    public const string DONE = 'done';
    public const string MISSING = 'missing';

    public function __construct(
        public string $source, // 'content' | 'mockup'
        public string $pageId,
        public string $pageName,
        public string $where, // element id (content) or slot number (mockup)
        public string $path,
        public string $label,
        public string $action,
        public null|string $fileId = null,
    ) {
    }

    public function isPending(): bool
    {
        return $this->action === self::CREATE || $this->action === self::LINK;
    }
}
