<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use DateTimeImmutable;

#[ApiResource(
    shortName: 'SocialNetworkTemplate',
    operations: [
        new GetCollection(
            uriTemplate: '/projects/{projectId}/social-network-templates',
            // projectId is not an identifier of this resource — it scopes the
            // collection. Declaring it as a Link on this DTO (empty identifiers)
            // registers the route variable and hands it to the provider via
            // $uriVariables without triggering parent auto-loading.
            uriVariables: [
                'projectId' => new Link(
                    fromClass: SocialNetworkTemplateResponse::class,
                    identifiers: [],
                    parameterName: 'projectId',
                ),
            ],
            provider: SocialNetworkTemplatesProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            paginationEnabled: false,
            // Keep null fields visible so consumers know which optional values
            // (categoryId, previewImageUrl, input.maxLength, …) are explicitly
            // unset versus accidentally missing.
            normalizationContext: ['skip_null_values' => false],
        ),
    ],
)]
final readonly class SocialNetworkTemplateResponse
{
    /**
     * @param list<SocialNetworkTemplateVariantResponse> $variants
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
