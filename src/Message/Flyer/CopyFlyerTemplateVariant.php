<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Flyer;

use Ramsey\Uuid\UuidInterface;

/**
 * Duplicates a variant within its template. Unlike the social-network copy
 * (which targets another fixed dimension), a flyer copy keeps the original's
 * free-form dimension — the designer adjusts it on the new variant if needed.
 */
readonly final class CopyFlyerTemplateVariant
{
    public function __construct(
        public UuidInterface $originalVariantId,
        public UuidInterface $newVariantId,
    ) {
    }
}
