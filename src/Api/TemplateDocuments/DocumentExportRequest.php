<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

/**
 * The document fill: `fields` (shared fieldId → value) + `pages` (pageId →
 * the single-variant export body of that page: `inputs`, `images` — a
 * transform may ride on a slot bound to a shared image field —,
 * `shapeColors`, `offsets` — the page's own element moves, keyed by its
 * variant's element ids, never shared —, `rows` — the page's repeatable
 * rows, the single-variant export's `rows`, per page) + `format`.
 */
final class DocumentExportRequest
{
    /** @var array<string, mixed> */
    public array $fields = [];

    /** @var array<string, mixed> */
    public array $pages = [];

    /** `pdf` (print, mm/cm only) | `pdf-screen` (default) | `png` (ZIP). */
    public null|string $format = null;

    /**
     * Export only. A page made from an AI scan may still show the grey
     * stand-in picture (a decorative image nobody replaced in the editor, or
     * a full-page background the body supplies no picture for). Such an
     * export is refused with 400 `unfilled_pictures` (`count` — summed over
     * the pages —, `variantId` of the first such page, `editorUrl`) unless
     * this is `true`. The page preview ignores it.
     */
    public bool $allowUnfilled = false;
}
