<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use DateTimeImmutable;

/**
 * One gallery image a consumer may drop into a specific template image
 * placeholder. The collection is scoped to the variant + placeholder and
 * contains only images from the folders the designer allowed for that slot
 * (for an UNRESTRICTED slot that is the whole gallery — root files come back
 * with a null `directoryId`/`directoryName`). Reference `id` in the export
 * `images` map.
 *
 * Canonical path plus two deprecated aliases (the former custom-template and
 * social-network module paths) — one provider over the same unified data.
 */
#[ApiResource(
    shortName: 'TemplatePlaceholderGalleryImage',
    operations: [
        new GetCollection(
            uriTemplate: '/template-variants/{variantId}/placeholders/{inputId}/images',
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
            name: 'api_template_variant_placeholder_gallery',
        ),
        new GetCollection(
            uriTemplate: '/custom-template-variants/{variantId}/placeholders/{inputId}/images',
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
            deprecationReason: 'Deprecated alias — use GET /api/template-variants/{variantId}/placeholders/{inputId}/images.',
            name: 'api_template_variant_placeholder_gallery_legacy_custom',
        ),
        new GetCollection(
            uriTemplate: '/social-network-template-variants/{variantId}/placeholders/{inputId}/images',
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
            deprecationReason: 'Deprecated alias — use GET /api/template-variants/{variantId}/placeholders/{inputId}/images.',
            name: 'api_template_variant_placeholder_gallery_legacy_social',
        ),
    ],
)]
final readonly class PlaceholderGalleryImageResponse
{
    public function __construct(
        public string $id,
        public string $url,
        public null|string $directoryId,
        public null|string $directoryName,
        public DateTimeImmutable $uploadedAt,
    ) {
    }
}
