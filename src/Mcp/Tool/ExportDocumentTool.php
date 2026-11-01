<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Result\CallToolResult;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Mcp\Fill\DocumentFillInput;
use WBoost\Web\Mcp\Fill\DocumentLoader;
use WBoost\Web\Mcp\Fill\VariantFill;
use WBoost\Web\Mcp\Response\ExportDocumentResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Repository\TemplateDocumentExportVersionRepository;
use WBoost\Web\Services\TemplateDocument\DocumentApiValues;
use WBoost\Web\Services\TemplateDocument\DocumentExportArchive;
use WBoost\Web\Services\TemplateDocument\RecordDocumentExportVersion;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentExporter;
use WBoost\Web\Services\Usage\RecordExportUsage;
use WBoost\Web\Value\DocumentFillValues;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportFormat;

/**
 * `export_document` — the multi-page deliverable: the whole filled document as
 * ONE file. Strict like `export_variant` (a refused value or an overflow is a
 * refusal naming the page), and every page is resolved before any renders.
 *
 * Two budgets keep it usable from a chat client:
 * - TIME — a print page is two renders + a CMYK pass (several seconds), so the
 *   print PDF is capped at {@see MAX_PRINT_PAGES} pages here; longer documents
 *   are exported on the web fill page or over the REST API;
 * - SIZE — the file comes back as an embedded blob; above
 *   {@see MAX_BYTES} it is refused (with the fill URL) rather than flooding
 *   the conversation.
 */
#[McpToolScope(McpScope::TemplatesExport)]
readonly final class ExportDocumentTool
{
    private const int MAX_PRINT_PAGES = 8;
    private const int MAX_BYTES = 25_000_000;

    public function __construct(
        private DocumentLoader $loader,
        private VariantFill $fill,
        private TemplateDocumentExporter $exporter,
        private DocumentExportArchive $archive,
        private RecordExportUsage $recordExportUsage,
        private UrlGeneratorInterface $urlGenerator,
        private RecordDocumentExportVersion $recordExportVersion,
        private DocumentFillInput $fillInput,
        private TemplateDocumentExportVersionRepository $versionRepository,
    ) {
    }

    /**
     * Exports a filled multi-page document (find_templates documents[]) as ONE
     * file and returns it as an embedded resource.
     *
     * - fields: shared fieldId → value (text: the field's textMode shape — a
     *   string, {runs} for rich, …; image: a gallery image id or {imageId,
     *   hide}; shape: "#rrggbb"). It fills the bound input on every page and wins
     *   over that page's own value.
     * - pages: pageId → that page's fill, exactly like export_variant's body
     *   for the page's variantId: {inputs, images, shapeColors}. Input ids are
     *   the page variant's own (describe_variant). Pages left out export as
     *   designed (sample texts).
     * - format: "pdf-screen" (default — sRGB, light, for sending), "pdf"
     *   (print: PDF/X-1a CMYK with bleed + crop marks; mm / cm documents only,
     *   at most 8 pages here) or "png" (ZIP of one PNG per page, page-ordered).
     *
     * - versionId: re-export a stored version (document_history "list") —
     *   its fill is the base and `fields` / `pages` given here override it
     *   key by key ("the March offer, only with a new customer name").
     *
     * Preview a page first with render_variant (its variantId + the same
     * inputs) — it is fast and lossy; this call renders every page in full.
     * Every export lands in the document's history (document_history).
     *
     * @param string $documentId find_templates documents[].id
     * @param array<string, mixed> $fields shared fieldId → value
     * @param array<string, mixed> $pages pageId → {inputs, images, shapeColors}
     * @param null|string $versionId document_history versions[].id to start from
     */
    #[McpTool(name: 'export_document')]
    public function __invoke(
        string $documentId,
        #[Schema(type: 'object', additionalProperties: true)]
        array $fields = [],
        #[Schema(type: 'object', additionalProperties: true)]
        array $pages = [],
        #[Schema(enum: ['pdf-screen', 'pdf', 'png'])]
        string $format = 'pdf-screen',
        null|string $versionId = null,
    ): CallToolResult {
        $document = $this->loader->viewable($documentId);
        $exportFormat = ExportFormat::tryFrom(strtolower($format));

        if ($exportFormat === null) {
            throw new ToolCallException('format must be "pdf-screen", "pdf" or "png".');
        }

        $documentPages = $document->pages();
        $fillUrl = $this->urlGenerator->generate('template_document_fill', ['documentId' => $document->id->toString()], UrlGeneratorInterface::ABSOLUTE_URL);

        if ($documentPages === []) {
            throw new ToolCallException('The document has no pages yet — attach templates with manage_document "set_pages" or add one with "add_page".');
        }

        if ($exportFormat === ExportFormat::Pdf && !$document->dimension->isPrintFormat()) {
            throw new ToolCallException('The print PDF exists only for print formats (mm / cm); this document is a screen (px) format — export it as "pdf-screen" or "png".');
        }

        if ($exportFormat === ExportFormat::Pdf && count($documentPages) > self::MAX_PRINT_PAGES) {
            throw new ToolCallException(sprintf('A print PDF of %d pages takes too long for a chat tool call (limit %d pages here). Export it on the web fill page (%s) or over the REST API, or use "pdf-screen" here.', count($documentPages), self::MAX_PRINT_PAGES, $fillUrl));
        }

        $warnings = [];
        $base = null;

        if ($versionId !== null && trim($versionId) !== '') {
            $base = DocumentFillInput::versionOf(
                $document,
                Uuid::isValid(trim($versionId)) ? $this->versionRepository->find(Uuid::fromString(trim($versionId))) : null,
                trim($versionId),
            );
        }

        ['fields' => $fields, 'pages' => $pages] = $this->fillInput->resolve($document, $base, $fields, $pages, $warnings);

        // Resolve EVERY page before rendering ANY (strict, like export_variant).
        $resolved = [];
        $pageValues = [];
        foreach ($documentPages as $page) {
            $values = DocumentApiValues::forPage($document, $page, $fields, $pages[$page->id->toString()] ?? []);
            $pageValues[$page->template->id->toString()] = $values;
            $inputs = VariantFill::stringKeyed($values['inputs']);
            $images = VariantFill::stringKeyed($values['images']);
            $shapeColors = VariantFill::stringKeyed($values['shapeColors']);

            foreach (VariantFill::warnings($page->variant, $inputs, $images, $shapeColors) as $warning) {
                $warnings[] = sprintf('Page %d (%s): %s', $page->position + 1, $page->template->name, $warning);
            }

            try {
                $overrides = $this->fill->withShapeColors($page->variant, $this->fill->texts($page->variant, $inputs), $shapeColors);
                $imageOverrides = $this->fill->images($page->variant, $images);
            } catch (ToolCallException $refused) {
                throw new ToolCallException(sprintf('Page %d (%s, pageId %s): %s', $page->position + 1, $page->template->name, $page->id->toString(), $refused->getMessage()));
            }

            $resolved[] = [$page, $overrides, $imageOverrides];
        }

        try {
            $bytes = $this->exporter->export($document, $exportFormat, $resolved, strictContainerOverflow: true);
        } catch (TemplateRenderUnavailable) {
            throw VariantFill::rendererBusy();
        } catch (ContainerOverflow $overflow) {
            foreach ($documentPages as $page) {
                if ($overflow->containerId !== null && str_contains($page->variant->canvas, $overflow->containerId)) {
                    throw new ToolCallException(sprintf('Page %d (%s): %s', $page->position + 1, $page->template->name, VariantFill::overflowFor($page->variant, $overflow)));
                }
            }

            throw new ToolCallException(sprintf('A text container (%s) overflows its maximum height — shorten its texts.', $overflow->containerId ?? '?'));
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new ToolCallException(sprintf('The exported file is %.1f MB — too large to hand over in a tool result. Download it on the web fill page (%s) or over the REST API.', strlen($bytes) / 1_000_000, $fillUrl));
        }

        foreach ($documentPages as $page) {
            $this->recordExportUsage->record($page->variant, ExportChannel::Mcp, $exportFormat);
        }

        $this->recordExportVersion->record($document, ExportChannel::Mcp, DocumentFillValues::fromApiPages($pageValues));

        $fileName = $this->archive->fileName($document, $exportFormat);
        $contentType = $this->archive->contentType($exportFormat);

        return new CallToolResult([
            VariantFill::summary(new ExportDocumentResponse(
                documentId: $document->id->toString(),
                documentName: $document->name,
                projectName: $document->project->name,
                format: $contentType,
                pageCount: count($documentPages),
                sizeBytes: strlen($bytes),
                fileName: $fileName,
                warnings: $warnings,
            )),
            EmbeddedResource::fromBlob(
                sprintf('wboost://exports/documents/%s/%s', $document->id->toString(), $fileName),
                base64_encode($bytes),
                $contentType,
            ),
        ]);
    }
}
