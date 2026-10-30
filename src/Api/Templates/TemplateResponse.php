<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use DateTimeImmutable;

#[ApiResource(
    shortName: 'Template',
    operations: [
        new GetCollection(
            uriTemplate: '/projects/{projectId}/templates',
            // projectId is not an identifier of this resource — it scopes the
            // collection. Declaring it as a Link on this DTO (empty identifiers)
            // registers the route variable and hands it to the provider via
            // $uriVariables without triggering parent auto-loading.
            uriVariables: [
                'projectId' => new Link(
                    fromClass: TemplateResponse::class,
                    identifiers: [],
                    parameterName: 'projectId',
                ),
            ],
            provider: TemplatesProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            paginationEnabled: false,
            // Keep null fields visible so consumers know which optional values
            // (categoryId, previewImageUrl, input.maxLength, …) are explicitly
            // unset versus accidentally missing.
            normalizationContext: ['skip_null_values' => false],
            name: 'api_templates_collection',
        ),
    ],
)]
final readonly class TemplateResponse
{
    /**
     * @param list<TemplateVariantResponse> $variants
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $position,
        public null|string $categoryId,
        public null|string $categoryName,
        public DateTimeImmutable $createdAt,
        public array $variants,
        // Non-null when the template is a synchronized GROUP (one design in
        // several dimensions, filled once): fill + export it through the
        // `/api/template-groups/{group.id}/…` endpoints. Only variants with
        // `groupMember: true` belong to the group fill.
        public null|TemplateGroupRefResponse $group = null,
        // Non-null when the template is a PAGE of a multi-page document: fill
        // + export it as part of the document
        // (`/api/template-documents/{document.id}/…`). Pages stay listed here
        // so existing consumers keep working.
        public null|TemplateDocumentRefResponse $document = null,
    ) {
    }
}
