<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentPage;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Print\PrintPdfRenderer;
use WBoost\Web\Services\Print\ScreenPdfRenderer;
use WBoost\Web\Value\ExportFormat;
use WBoost\Web\Value\RenderImageFormat;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Renders a filled document — pages ALREADY resolved by the caller (web:
 * lenient via GroupFillRenderer, API/MCP: strict via ApiExportRenderer) — as
 * ONE file: the multi-page print PDF (PDF/X-1a, print formats only), the
 * screen PDF ("PDF k odeslání", any size) or a ZIP of lossless PNGs.
 * Pages render sequentially; the caller releases the session lock first.
 */
readonly final class TemplateDocumentExporter
{
    public function __construct(
        private TemplateVariantImageRendererInterface $renderer,
        private PrintPdfRenderer $printPdfRenderer,
        private ScreenPdfRenderer $screenPdfRenderer,
        private DocumentExportArchive $archive,
    ) {
    }

    /**
     * @param list<array{TemplateDocumentPage, ResolvedInputOverrides, null|ResolvedImageOverrides}> $pages
     *
     * @throws \WBoost\Web\Exceptions\ContainerOverflow
     * @throws \WBoost\Web\Exceptions\TemplateRenderUnavailable
     */
    public function export(TemplateDocument $document, ExportFormat $format, array $pages, bool $strictContainerOverflow): string
    {
        if ($pages === []) {
            throw new \InvalidArgumentException('A document without pages cannot be exported.');
        }

        $variantPages = array_map(
            static fn (array $page): array => [$page[0]->variant, $page[1], $page[2]],
            $pages,
        );

        return match ($format) {
            ExportFormat::Pdf => $document->dimension->isPrintFormat()
                ? $this->printPdfRenderer->renderDocument($variantPages, $document->name, $strictContainerOverflow)
                : throw new \InvalidArgumentException('The print PDF exists for print formats (mm / cm) only.'),
            ExportFormat::PdfScreen => $this->screenPdfRenderer->renderDocument($variantPages, $document->name, $strictContainerOverflow),
            ExportFormat::Png => $this->archive->zip(array_map(
                fn (array $page): array => [$page[0], $this->renderer->renderToBytes(
                    $page[0]->variant,
                    $page[1],
                    $page[2],
                    $strictContainerOverflow,
                    format: RenderImageFormat::Png,
                )],
                $pages,
            )),
        };
    }
}
