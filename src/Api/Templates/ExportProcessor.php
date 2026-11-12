<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Exceptions\InvalidExportValue;
use WBoost\Web\Exceptions\InvalidRichTextValue;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Services\AiScan\Template\UnfilledExportGuard;
use WBoost\Web\Services\Template\ApiExportRenderer;
use WBoost\Web\Services\Template\ApiRepeatableRows;
use WBoost\Web\Services\Template\ApiSubjectLoader;
use WBoost\Web\Services\Template\RecordExportVersion;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Services\Template\TemplateExportFileNames;
use WBoost\Web\Services\Usage\RecordExportUsage;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportFillValues;
use WBoost\Web\Value\ExportFormat;

/**
 * `POST /api/template-variants/{id}/export` — the FINAL render: a lossless PNG
 * (or, with `format: "pdf"` on a print format, the print PDF) that counts as an export (usage + a re-loadable version in the variant's
 * "Historie exportů"). Live previews must use the `/preview` sibling
 * ({@see PreviewProcessor}), which records nothing.
 *
 * @implements ProcessorInterface<ExportRequest, Response>
 */
final readonly class ExportProcessor implements ProcessorInterface
{
    public function __construct(
        private ApiSubjectLoader $subjectLoader,
        private ApiExportRenderer $apiExportRenderer,
        private RecordExportUsage $recordExportUsage,
        private RecordExportVersion $recordExportVersion,
        private TemplateExportFileNames $fileNames,
        private UnfilledExportGuard $unfilledGuard,
    ) {
    }

    /**
     * @param ExportRequest $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $variant = $this->subjectLoader->variant($uriVariables['id'] ?? null);

        $format = $data->format === null ? ExportFormat::Png : ExportFormat::tryFrom(strtolower($data->format));

        // The screen PDF is a document format: here it is as unknown as any
        // other value.
        if ($format === null || !$format->isVariantFormat()) {
            return ExportErrorResponse::invalidFormat();
        }

        if ($format === ExportFormat::Pdf && !$variant->dimension->isPrintFormat()) {
            return ExportErrorResponse::pdfNotAvailable();
        }

        try {
            // Repeatable rows folded into the maps up front: the version
            // stores them like the web fill does (values under the rows'
            // slot ids + the counts).
            $applied = ApiRepeatableRows::apply(RepeatableRows::expand($variant), $data->rows, $data->inputs, $data->images, $data->shapeColors);

            // A grey stand-in an AI scan left behind is never exported
            // silently: refused (structured 400) unless `allowUnfilled`.
            $refusal = $this->unfilledGuard->apiRefusal($data->allowUnfilled, [[$variant, $applied->images]]);

            if ($refusal !== null) {
                return $refusal;
            }

            $bytes = $this->apiExportRenderer->renderExport(
                $format,
                $variant,
                sprintf('%s – %s', $variant->template->name, $variant->dimension->label()),
                $applied->inputs,
                $applied->images,
                $applied->shapeColors,
                $data->offsets,
                $applied,
            );
        } catch (InvalidRichTextValue|InvalidExportValue|ContainerOverflow|TemplateRenderUnavailable $error) {
            return ExportErrorResponse::fromThrowable($error) ?? throw $error;
        }

        $this->recordExportUsage->record($variant, ExportChannel::Api, $format);
        // The moves as rendered — strict-resolved (already accepted above),
        // canonical, under the variant's id like the web fill stores them.
        // Row counts: every row of the design (defaults for rows the body
        // left out) — the web fill's shape; none for a design without rows.
        $fill = ExportFillValues::fromApiRequest($applied->inputs, $applied->images, $applied->shapeColors)
            ->withRows(RepeatableRows::normalizeCounts([$variant], $applied->counts))
            ->withOffsets([$variant->id->toString() => ApiExportRenderer::resolveOffsets($variant, $data->offsets)]);
        $this->recordExportVersion->recordVariant($variant, ExportChannel::Api, $fill);

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => $format->contentType(),
            'Content-Disposition' => $this->fileNames->variant($variant, $format, $fill)->disposition('inline'),
            'Cache-Control' => 'no-store',
        ]);
    }
}
