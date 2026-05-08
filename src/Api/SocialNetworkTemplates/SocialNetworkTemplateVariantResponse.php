<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

final readonly class SocialNetworkTemplateVariantResponse
{
    /**
     * @param list<SocialNetworkTemplateVariantInputResponse> $inputs
     */
    public function __construct(
        public string $id,
        public string $dimension,
        public int $width,
        public int $height,
        public null|string $previewImageUrl,
        public string $backgroundImageUrl,
        public string $exportUrl,
        public array $inputs,
    ) {
    }
}
