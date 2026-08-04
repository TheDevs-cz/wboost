<?php

declare(strict_types=1);

namespace WBoost\Web\Message\SocialNetwork;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\DimensionPreset;

readonly final class CopySocialNetworkTemplateVariant
{
    public function __construct(
        public UuidInterface $originalVariantId,
        public UuidInterface $newVariantId,
        public DimensionPreset $dimension,
    ) {
    }
}
