<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

final readonly class SocialNetworkTemplateVariantInputResponse
{
    public function __construct(
        public string $id,
        public null|string $name,
        public null|int $maxLength,
        public bool $locked,
        public bool $uppercase,
        public null|string $description,
        public bool $hidable,
        public null|SocialNetworkTemplateVariantInputFrameResponse $frame,
    ) {
    }
}
