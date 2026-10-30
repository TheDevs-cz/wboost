<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

/**
 * The document fill: `fields` (shared fieldId → value) + `pages` (pageId →
 * the single-variant export body of that page: `inputs`, `images` — a
 * transform may ride on a slot bound to a shared image field —,
 * `shapeColors`) + `format`.
 */
final class DocumentExportRequest
{
    /** @var array<string, mixed> */
    public array $fields = [];

    /** @var array<string, mixed> */
    public array $pages = [];

    /** `pdf` (print, mm/cm only) | `pdf-screen` (default) | `png` (ZIP). */
    public null|string $format = null;
}
