<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use DateTimeImmutable;
use WBoost\Web\Api\ExportVersions\ExportVersionSummaryResponse;

/**
 * One entry of a multi-page document's EXPORT HISTORY — the list the web
 * document fill page shows: every distinct document fill that was exported
 * (web, API, MCP) or saved as a draft, deduplicated, pinned first (most
 * recently pinned on top), then freshest first.
 *
 * Load a version's fill with `GET /api/template-document-export-versions/{id}`;
 * rename / pin it with `PATCH` on the same URL.
 */
#[ApiResource(
    shortName: 'TemplateDocumentExportVersion',
    operations: [
        new GetCollection(
            uriTemplate: '/template-documents/{documentId}/export-versions',
            uriVariables: [
                'documentId' => new Link(fromClass: TemplateDocumentExportVersionResponse::class, identifiers: [], parameterName: 'documentId'),
            ],
            provider: TemplateDocumentExportVersionsProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            paginationEnabled: false,
            normalizationContext: ['skip_null_values' => false],
            name: 'api_template_document_export_versions',
        ),
    ],
)]
final readonly class TemplateDocumentExportVersionResponse
{
    public function __construct(
        public string $id,
        public string $documentId,
        /** User-given label; null = unnamed (list it under `lastExportedAt`). Max 120 chars. */
        public null|string $name,
        public bool $pinned,
        public null|DateTimeImmutable $pinnedAt,
        /** A saved work-in-progress ("Uložit rozpracované"), never exported yet. */
        public bool $draft,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastExportedAt,
        public int $exportCount,
        /** The channel of the LATEST export / save: web | api | mcp. */
        public string $channel,
        public ExportVersionSummaryResponse $summary,
    ) {
    }
}
