<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use DateTimeImmutable;
use WBoost\Web\Api\ExportVersions\ExportVersionSummaryResponse;
use WBoost\Web\Api\ExportVersions\ExportVersionUpdateRequest;

/**
 * One document version WITH its fill, in the document export body's own
 * shape — `{fields: {fieldId: value}, pages: {pageId: {inputs, images,
 * shapeColors}}}` — ready to put back into a form and export again. Seeded
 * LENIENTLY against the current design (see
 * {@see \WBoost\Web\Services\TemplateDocument\DocumentExportVersionApiView}).
 */
#[ApiResource(
    shortName: 'TemplateDocumentExportVersionDetail',
    operations: [
        new Get(
            uriTemplate: '/template-document-export-versions/{versionId}',
            uriVariables: [
                'versionId' => new Link(fromClass: TemplateDocumentExportVersionDetailResponse::class, identifiers: [], parameterName: 'versionId'),
            ],
            provider: TemplateDocumentExportVersionProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            normalizationContext: ['skip_null_values' => false, 'preserve_empty_objects' => true],
            name: 'api_template_document_export_version',
        ),
        new Patch(
            uriTemplate: '/template-document-export-versions/{versionId}',
            uriVariables: [
                'versionId' => new Link(fromClass: TemplateDocumentExportVersionDetailResponse::class, identifiers: [], parameterName: 'versionId'),
            ],
            inputFormats: ['json' => ['application/json']],
            input: ExportVersionUpdateRequest::class,
            read: false,
            processor: TemplateDocumentExportVersionUpdateProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            normalizationContext: ['skip_null_values' => false, 'preserve_empty_objects' => true],
            name: 'api_template_document_export_version_update',
        ),
    ],
)]
final readonly class TemplateDocumentExportVersionDetailResponse
{
    /**
     * @param array{fields: array<string, mixed>|\ArrayObject<string, mixed>, pages: array<string, mixed>|\ArrayObject<string, mixed>} $fill
     */
    public function __construct(
        public string $id,
        public string $documentId,
        public null|string $name,
        public bool $pinned,
        public null|DateTimeImmutable $pinnedAt,
        public bool $draft,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastExportedAt,
        public int $exportCount,
        public string $channel,
        public ExportVersionSummaryResponse $summary,
        public array $fill,
    ) {
    }
}
