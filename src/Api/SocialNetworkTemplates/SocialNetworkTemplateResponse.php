<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use DateTimeImmutable;

#[ApiResource(
    shortName: 'SocialNetworkTemplate',
    operations: [
        new GetCollection(
            uriTemplate: '/social-network-templates',
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
