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
use WBoost\Web\Services\Template\ApiExportRenderer;
use WBoost\Web\Services\Template\ApiSubjectLoader;
use WBoost\Web\Services\Template\RecordExportVersion;
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
            $bytes = $this->apiExportRenderer->renderExport(
                $format,
                $variant,
                sprintf('%s – %s', $variant->template->name, $variant->dimension->label()),
                $data->inputs,
                $data->images,
                $data->shapeColors,
            );
        } catch (InvalidRichTextValue|InvalidExportValue|ContainerOverflow|TemplateRenderUnavailable $error) {
            return ExportErrorResponse::fromThrowable($error) ?? throw $error;
        }

        $this->recordExportUsage->record($variant, ExportChannel::Api, $format);
        $this->recordExportVersion->recordVariant(
            $variant,
            ExportChannel::Api,
            ExportFillValues::fromApiRequest($data->inputs, $data->images, $data->shapeColors),
        );

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => $format->contentType(),
            'Content-Disposition' => sprintf('inline; filename="%s.%s"', $variant->id->toString(), $format->extension()),
            'Cache-Control' => 'no-store',
        ]);
    }
}
