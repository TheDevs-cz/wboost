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
        // Canonical listing plus two deprecated aliases kept for existing
        // service consumers: the former custom-template and social-network
        // module paths. All three run the same provider over the same unified
        // data and return the FULL merged template list.
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
        new GetCollection(
            uriTemplate: '/projects/{projectId}/custom-templates',
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
            normalizationContext: ['skip_null_values' => false],
            deprecationReason: 'Deprecated alias — use GET /api/projects/{projectId}/templates.',
            name: 'api_templates_collection_legacy_custom',
        ),
        new GetCollection(
            uriTemplate: '/projects/{projectId}/social-network-templates',
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
            normalizationContext: ['skip_null_values' => false],
            deprecationReason: 'Deprecated alias — use GET /api/projects/{projectId}/templates.',
            name: 'api_templates_collection_legacy_social',
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
    ) {
    }
}
