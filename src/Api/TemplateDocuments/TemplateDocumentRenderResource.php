<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;

/**
 * Filling + exporting a multi-page document. Body = DocumentExportRequest;
 * renders are STRICT (every page is resolved before any renders, so a 400
 * names its `pageId` without minutes of wasted renders). Previews are WebP and
 * recorded nowhere; exports count in usage per page.
 */
#[ApiResource(
    shortName: 'TemplateDocumentRender',
    operations: [
        new Post(
            uriTemplate: '/template-documents/{documentId}/preview/{pageId}',
            uriVariables: [
                'documentId' => new Link(fromClass: TemplateDocumentRenderResource::class, identifiers: [], parameterName: 'documentId'),
                'pageId' => new Link(fromClass: TemplateDocumentRenderResource::class, identifiers: [], parameterName: 'pageId'),
            ],
            input: DocumentExportRequest::class,
            output: false,
            read: false,
            processor: TemplateDocumentRenderProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            name: 'api_template_document_preview',
            openapi: new OpenApiOperation(summary: 'Live preview of ONE page (WebP, not recorded) with the shared fields applied.'),
        ),
        new Post(
            uriTemplate: '/template-documents/{documentId}/export',
            uriVariables: [
                'documentId' => new Link(fromClass: TemplateDocumentRenderResource::class, identifiers: [], parameterName: 'documentId'),
            ],
            input: DocumentExportRequest::class,
            output: false,
            read: false,
            processor: TemplateDocumentRenderProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            name: 'api_template_document_export',
            openapi: new OpenApiOperation(summary: 'Export the whole document as ONE file: `format` "pdf" (multi-page PDF/X-1a, print formats only), "pdf-screen" (default; sRGB, ~150 ppi) or "png" (ZIP of `{nn}-{page}.png`).'),
        ),
    ],
)]
final class TemplateDocumentRenderResource
{
}
