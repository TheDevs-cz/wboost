<?php

declare(strict_types=1);

namespace WBoost\Web\Api\CustomTemplates;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use DateTimeImmutable;

/**
 * One gallery image a consumer may drop into a specific custom-template image
 * placeholder. The collection is scoped to the variant + placeholder and
 * contains only images from the folders the designer allowed for that slot.
 * Reference `id` in the export `images` map.
 */
#[ApiResource(
    shortName: 'CustomTemplatePlaceholderGalleryImage',
    operations: [
        new GetCollection(
            uriTemplate: '/custom-template-variants/{variantId}/placeholders/{inputId}/images',
            // Neither variable identifies THIS resource — they scope the
            // collection. Empty-identifier Links register the route variables
            // and hand them to the provider without parent auto-loading.
            uriVariables: [
                'variantId' => new Link(
                    fromClass: PlaceholderGalleryImageResponse::class,
                    identifiers: [],
                    parameterName: 'variantId',
                ),
                'inputId' => new Link(
                    fromClass: PlaceholderGalleryImageResponse::class,
                    identifiers: [],
                    parameterName: 'inputId',
                ),
            ],
            provider: PlaceholderGalleryProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            paginationEnabled: false,
        ),
    ],
)]
final readonly class PlaceholderGalleryImageResponse
{
    public function __construct(
        public string $id,
        public string $url,
        public string $directoryId,
        public string $directoryName,
        public DateTimeImmutable $uploadedAt,
    ) {
    }
}
