<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Api\Templates\ExportErrorResponse;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentPage;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Exceptions\InvalidExportValue;
use WBoost\Web\Exceptions\InvalidRichTextValue;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Services\AiScan\Template\UnfilledExportGuard;
use WBoost\Web\Services\Template\ApiExportRenderer;
use WBoost\Web\Services\Template\ApiRepeatableRows;
use WBoost\Web\Services\Template\ApiSubjectLoader;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Services\Template\TemplateExportFileNames;
use WBoost\Web\Services\TemplateDocument\DocumentApiValues;
use WBoost\Web\Services\TemplateDocument\DocumentExportArchive;
use WBoost\Web\Services\TemplateDocument\RecordDocumentExportVersion;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentExporter;
use WBoost\Web\Services\Usage\RecordExportUsage;
use WBoost\Web\Value\DocumentFillValues;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportFormat;
use WBoost\Web\Value\RenderImageFormat;

/**
 * `POST /api/template-documents/{documentId}/preview/{pageId}` (WebP) and
 * `…/export` (one PDF / ZIP for the whole document).
 *
 * @implements ProcessorInterface<DocumentExportRequest, Response>
 */
final readonly class TemplateDocumentRenderProcessor implements ProcessorInterface
{
    /** Seconds — a 30-page print PDF is two renders + a CMYK pass per page. */
    private const int TIME_LIMIT = 600;

    public function __construct(
        private ApiSubjectLoader $subjectLoader,
        private ApiExportRenderer $apiExportRenderer,
        private TemplateDocumentExporter $exporter,
        private DocumentExportArchive $archive,
        private RecordExportUsage $recordExportUsage,
        private RecordDocumentExportVersion $recordExportVersion,
        private TemplateExportFileNames $fileNames,
        private UnfilledExportGuard $unfilledGuard,
    ) {
    }

    /**
     * @param DocumentExportRequest $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $document = $this->subjectLoader->document($uriVariables['documentId'] ?? null);

        if (array_key_exists('pageId', $uriVariables)) {
            return $this->preview($document, $this->subjectLoader->documentPage($document, $uriVariables['pageId']), $data);
        }

        return $this->export($document, $data);
    }

    private function preview(TemplateDocument $document, TemplateDocumentPage $page, DocumentExportRequest $data): Response
    {
        $values = DocumentApiValues::forPage($document, $page, $data->fields, $this->pageEntry($page, $data->pages));

        try {
            $bytes = $this->apiExportRenderer->render($page->variant, $values['inputs'], $values['images'], RenderImageFormat::Webp, $values['shapeColors'], $values['offsets'], ApiRepeatableRows::raw($values['rows']));
        } catch (InvalidRichTextValue|InvalidExportValue|ContainerOverflow|TemplateRenderUnavailable $error) {
            return self::error($error, $page);
        }

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => RenderImageFormat::Webp->contentType(),
            'Cache-Control' => 'no-store',
        ]);
    }

    private function export(TemplateDocument $document, DocumentExportRequest $data): Response
    {
        $format = $data->format === null ? ExportFormat::PdfScreen : ExportFormat::tryFrom(strtolower($data->format));

        if ($format === null) {
            return new JsonResponse([
                'error' => 'format must be "pdf", "pdf-screen" or "png".',
                'code' => 'invalid_format',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($format === ExportFormat::Pdf && !$document->dimension->isPrintFormat()) {
            return new JsonResponse([
                'error' => 'The print PDF exists only for print formats (mm / cm); use "pdf-screen" for this document.',
                'code' => 'pdf_not_available',
            ], Response::HTTP_BAD_REQUEST);
        }

        $pages = $document->pages();

        if ($pages === []) {
            return new JsonResponse(['error' => 'The document has no pages.', 'code' => 'document_empty'], Response::HTTP_BAD_REQUEST);
        }

        // (Not on the command line: there the limit would outlive this call and end
        // a long test run or a console command 600 s later — PHP counts wall time.)
        if (\PHP_SAPI !== 'cli') {
            set_time_limit(self::TIME_LIMIT);
        }

        // Resolve EVERY page before rendering ANY: a refused value on the
        // last page must not arrive after minutes of renders.
        $resolved = [];
        $pageValues = [];
        $pageOffsets = [];
        $pageRowCounts = [];
        $exports = [];
        foreach ($pages as $page) {
            $values = DocumentApiValues::forPage($document, $page, $data->fields, $this->pageEntry($page, $data->pages));
            $templateId = $page->template->id->toString();

            try {
                // The page's rows folded into its maps first — the version
                // stores the values under the rows' slot ids + the counts.
                $applied = ApiRepeatableRows::apply(RepeatableRows::expand($page->variant), $values['rows'], $values['inputs'], $values['images'], $values['shapeColors']);
                [$overrides, $imageOverrides] = $this->apiExportRenderer->resolve($page->variant, $applied->inputs, $applied->images, $applied->shapeColors, $values['offsets'], $applied);
            } catch (InvalidRichTextValue|InvalidExportValue $error) {
                return self::error($error, $page);
            }

            $pageValues[$templateId] = ['inputs' => $applied->inputs, 'images' => $applied->images, 'shapeColors' => $applied->shapeColors];
            $pageRowCounts[$templateId] = RepeatableRows::normalizeCounts([$page->variant], $applied->counts);

            $resolved[] = [$page, $overrides, $imageOverrides];
            $exports[] = [$page->variant, $applied->images];
            // The page's moves as rendered (strict, canonical) — stored per
            // page under its variant, like the web document page does.
            $pageOffsets[$page->template->id->toString()] = [$page->variant->id->toString() => $overrides->offsets];
        }

        // A grey stand-in an AI scan left behind (summed over the pages) is
        // never exported silently: refused unless `allowUnfilled`.
        $refusal = $this->unfilledGuard->apiRefusal($data->allowUnfilled, $exports);

        if ($refusal !== null) {
            return $refusal;
        }

        try {
            $bytes = $this->exporter->export($document, $format, $resolved, strictContainerOverflow: true);
        } catch (ContainerOverflow|TemplateRenderUnavailable $error) {
            return self::error($error, null);
        }

        foreach ($pages as $page) {
            $this->recordExportUsage->record($page->variant, ExportChannel::Api, $format);
        }

        $fill = DocumentFillValues::fromApiPages($pageValues, $pageOffsets, $pageRowCounts);
        $this->recordExportVersion->record($document, ExportChannel::Api, $fill);

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => $this->archive->contentType($format),
            'Content-Disposition' => $this->fileNames->document($document, $format, $fill)->disposition(),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * The body's entry of this page — keyed by pageId, or (leniently) by the
     * page's templateId.
     *
     * @param array<string, mixed> $pages
     * @return array<array-key, mixed>
     */
    private function pageEntry(TemplateDocumentPage $page, array $pages): array
    {
        $entry = $pages[$page->id->toString()] ?? $pages[$page->template->id->toString()] ?? [];

        return is_array($entry) ? $entry : [];
    }

    private static function error(\Throwable $error, null|TemplateDocumentPage $page): Response
    {
        $response = ExportErrorResponse::fromThrowable($error, $page?->variant->id->toString()) ?? throw $error;
        $body = json_decode((string) $response->getContent(), true);

        if ($page !== null && is_array($body)) {
            $response->setData([...$body, 'pageId' => $page->id->toString()]);
        }

        return $response;
    }
}
