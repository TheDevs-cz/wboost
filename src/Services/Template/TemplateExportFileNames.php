<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentPage;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateDocumentExportVersionRepository;
use WBoost\Web\Repository\TemplateDocumentRepository;
use WBoost\Web\Repository\TemplateExportVersionRepository;
use WBoost\Web\Value\DocumentFillValues;
use WBoost\Web\Value\DownloadFileName;
use WBoost\Web\Value\ExportFillValues;
use WBoost\Web\Value\ExportFormat;

/**
 * Names of every template-module download — web, API and MCP alike — as
 * `<template>[ - <size>][ - <page>][ - <version>].<ext>`:
 *
 * - single template: its name, plus the size when the template has several;
 *   a page of a document is `<document> - <page>` instead;
 * - group: `<group> - <size>` per dimension, `<group>.zip` / `<group> - PDF.zip`;
 * - document: `<document>.pdf|zip`, ZIP entries `<document> - 01 - <page>.png`
 *   (order-prefixed so a file browser keeps the page order);
 * - `<version>`: the name of the export version the download landed on, when
 *   the user named it in "Historie exportů". Pass the fill values AFTER the
 *   export was recorded (the recording is synchronous): the version is the
 *   exported row with this exact fill, so an edited fill — a new, unnamed
 *   version — never inherits a stale name.
 */
final readonly class TemplateExportFileNames
{
    public function __construct(
        private TemplateDocumentRepository $documentRepository,
        private TemplateExportVersionRepository $versionRepository,
        private TemplateDocumentExportVersionRepository $documentVersionRepository,
    ) {
    }

    public function variant(TemplateVariant $variant, ExportFormat $format, null|ExportFillValues $fill = null): DownloadFileName
    {
        $version = $fill !== null ? $this->versionRepository->findDuplicate($variant->id, null, $fill->hash())?->name : null;
        $page = $this->documentRepository->pageOfVariant($variant->id);

        if ($page !== null) {
            return DownloadFileName::fromParts([$page->document->name, $page->template->name, $version], $format->extension());
        }

        $template = $variant->template;
        $size = count($template->variants()) > 1 ? self::size($variant) : null;

        return DownloadFileName::fromParts([$template->name, $size, $version], $format->extension(), 'sablona');
    }

    public function groupMember(TemplateGroup $group, TemplateVariant $variant, ExportFormat $format, null|ExportFillValues $fill = null): DownloadFileName
    {
        return DownloadFileName::fromParts([$group->name, self::size($variant), $this->groupVersion($group, $fill)], $format->extension(), 'skupina');
    }

    public function groupArchive(TemplateGroup $group, ExportFormat $format, null|ExportFillValues $fill = null): DownloadFileName
    {
        return DownloadFileName::fromParts(
            [$group->name, $format === ExportFormat::Png ? null : 'PDF', $this->groupVersion($group, $fill)],
            'zip',
            'skupina',
        );
    }

    public function document(TemplateDocument $document, ExportFormat $format, null|DocumentFillValues $fill = null): DownloadFileName
    {
        $version = $fill !== null ? $this->documentVersionRepository->findDuplicate($document->id, $fill->hash())?->name : null;

        return DownloadFileName::fromParts(
            [$document->name, $version],
            $format === ExportFormat::Png ? 'zip' : $format->extension(),
            'dokument',
        );
    }

    /**
     * @param int $number 1-based position of the page in the export
     */
    public function documentPage(TemplateDocument $document, TemplateDocumentPage $page, int $number, int $pageCount): DownloadFileName
    {
        $digits = max(2, strlen((string) $pageCount));

        return DownloadFileName::fromParts(
            [$document->name, sprintf('%0' . $digits . 'd', $number), $page->template->name],
            'png',
            'dokument',
        );
    }

    /**
     * "1:1" → "1x1" (a colon is reserved on Windows/macOS); "210 × 297 mm"
     * stays as is.
     */
    private static function size(TemplateVariant $variant): string
    {
        return str_replace(':', 'x', $variant->dimension->label());
    }

    private function groupVersion(TemplateGroup $group, null|ExportFillValues $fill): null|string
    {
        return $fill !== null ? $this->versionRepository->findDuplicate(null, $group->id, $fill->hash())?->name : null;
    }
}
