<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use DateTimeImmutable;

/**
 * Multi-page documents ("Dokumenty"): ordinary templates bound into ONE
 * ordered, exportable document — one page size, each page a template +
 * its variant of that size, plus shared fields filled once for every bound
 * input. Every write goes through the same CQRS messages as the web, so the
 * same invariants answer (400 `{error, code}` — codes in
 * docs/api/consumer-prompt.md). Fill + export: TemplateDocumentRenderResource.
 */
#[ApiResource(
    shortName: 'TemplateDocument',
    operations: [
        new GetCollection(
            uriTemplate: '/projects/{projectId}/template-documents',
            uriVariables: [
                'projectId' => new Link(fromClass: TemplateDocumentResponse::class, identifiers: [], parameterName: 'projectId'),
            ],
            provider: TemplateDocumentsProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            paginationEnabled: false,
            normalizationContext: ['skip_null_values' => false],
            name: 'api_template_documents',
            openapi: new OpenApiOperation(summary: 'List the multi-page documents of a project (pages with their variant shape, shared fields).'),
        ),
        new Get(
            uriTemplate: '/template-documents/{documentId}',
            uriVariables: [
                'documentId' => new Link(fromClass: TemplateDocumentResponse::class, identifiers: [], parameterName: 'documentId'),
            ],
            provider: TemplateDocumentsProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            normalizationContext: ['skip_null_values' => false],
            name: 'api_template_document',
            openapi: new OpenApiOperation(summary: 'One multi-page document.'),
        ),
        new Post(
            uriTemplate: '/projects/{projectId}/template-documents',
            uriVariables: [
                'projectId' => new Link(fromClass: TemplateDocumentResponse::class, identifiers: [], parameterName: 'projectId'),
            ],
            input: CreateTemplateDocumentRequest::class,
            read: false,
            processor: TemplateDocumentWriteProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            normalizationContext: ['skip_null_values' => false],
            name: 'api_template_document_create',
            openapi: new OpenApiOperation(summary: 'Create a document: `{name, categoryId?, dimension {unit, unitWidth, unitHeight, preset?, bleedMm?, cropMarks?}, templateIds?}` — the templates become its first pages (designer + project owner).'),
        ),
        new Patch(
            uriTemplate: '/template-documents/{documentId}',
            uriVariables: [
                'documentId' => new Link(fromClass: TemplateDocumentResponse::class, identifiers: [], parameterName: 'documentId'),
            ],
            inputFormats: ['json' => ['application/json', 'application/merge-patch+json']],
            input: UpdateTemplateDocumentRequest::class,
            read: false,
            processor: TemplateDocumentWriteProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            normalizationContext: ['skip_null_values' => false],
            name: 'api_template_document_update',
            openapi: new OpenApiOperation(summary: 'Rename / recategorise: `{name?, categoryId?}` (absent key = untouched, `categoryId: null` = uncategorised).'),
        ),
        new Put(
            uriTemplate: '/template-documents/{documentId}/pages',
            uriVariables: [
                'documentId' => new Link(fromClass: TemplateDocumentResponse::class, identifiers: [], parameterName: 'documentId'),
            ],
            input: SetTemplateDocumentPagesRequest::class,
            read: false,
            processor: TemplateDocumentWriteProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            normalizationContext: ['skip_null_values' => false],
            name: 'api_template_document_pages',
            openapi: new OpenApiOperation(summary: 'Replace the page list: `{templateIds: [...]}` in order — attach, detach (the template returns to the listing) and reorder in one idempotent call.'),
        ),
        new Post(
            uriTemplate: '/template-documents/{documentId}/pages',
            uriVariables: [
                'documentId' => new Link(fromClass: TemplateDocumentResponse::class, identifiers: [], parameterName: 'documentId'),
            ],
            input: AddTemplateDocumentPageRequest::class,
            read: false,
            processor: TemplateDocumentWriteProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            normalizationContext: ['skip_null_values' => false],
            name: 'api_template_document_add_page',
            openapi: new OpenApiOperation(summary: 'Add a BLANK page (a new template with one variant of the document size): `{name, position?}`. Answers the document; the new page\'s variant is then designed via MCP `set_design` or the web editor.'),
        ),
        new Put(
            uriTemplate: '/template-documents/{documentId}/fields',
            uriVariables: [
                'documentId' => new Link(fromClass: TemplateDocumentResponse::class, identifiers: [], parameterName: 'documentId'),
            ],
            input: SetTemplateDocumentFieldsRequest::class,
            read: false,
            processor: TemplateDocumentWriteProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            normalizationContext: ['skip_null_values' => false],
            name: 'api_template_document_fields',
            openapi: new OpenApiOperation(summary: 'Replace the shared fields: `{fields: [{id?, name, kind: "text"|"image"|"shape", bindings: [{pageId, inputId}]}]}` — at most one binding per page; keep `id` to keep a field.'),
        ),
        new Delete(
            uriTemplate: '/template-documents/{documentId}',
            uriVariables: [
                'documentId' => new Link(fromClass: TemplateDocumentResponse::class, identifiers: [], parameterName: 'documentId'),
            ],
            read: false,
            processor: TemplateDocumentWriteProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            name: 'api_template_document_delete',
            openapi: new OpenApiOperation(summary: 'Dissolve the document — its pages return to the template listing (page templates are never deleted over the API).'),
        ),
    ],
)]
final readonly class TemplateDocumentResponse
{
    /**
     * @param list<TemplateDocumentPageResponse> $pages
     * @param list<TemplateDocumentFieldResponse> $fields
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $name,
        /** Shares the template listing's sort space within its category. */
        public int $position,
        public null|string $categoryId,
        public null|string $categoryName,
        public DateTimeImmutable $createdAt,
        /** Page size label ("210 × 297 mm" / "4:5"), like a variant's `dimension`. */
        public string $dimension,
        public null|string $preset,
        public string $unit,
        public float $unitWidth,
        public float $unitHeight,
        /** Canvas pixels (300 DPI for mm / cm). */
        public int $width,
        public int $height,
        /** mm / cm → the print PDF (`format: "pdf"`) is available. */
        public bool $printFormat,
        public float $bleedMm,
        public bool $cropMarks,
        public array $pages,
        public array $fields,
    ) {
    }
}
