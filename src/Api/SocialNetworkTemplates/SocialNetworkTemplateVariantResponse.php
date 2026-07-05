<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

final readonly class SocialNetworkTemplateVariantResponse
{
    /**
     * @param list<SocialNetworkTemplateVariantInputResponse> $inputs
     * @param list<SocialNetworkTemplateVariantImageInputResponse> $imageInputs
     * @param list<SocialNetworkTemplateVariantContainerResponse> $containers
     */
    public function __construct(
        public string $id,
        public string $dimension,
        public int $width,
        public int $height,
        public null|string $previewImageUrl,
        public string $backgroundImageUrl,
        // Thumbnail served from the API host (preview render, or background as a
        // fallback). Consumers should use this instead of the store URLs above so
        // they never need to reach the object store directly.
        public string $thumbnailUrl,
        public string $exportUrl,
        public array $inputs,
        public array $imageInputs,
        public array $containers = [],
    ) {
    }
}
