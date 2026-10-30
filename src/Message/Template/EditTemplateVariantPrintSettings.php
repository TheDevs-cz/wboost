<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

use Ramsey\Uuid\UuidInterface;

readonly final class EditTemplateVariantPrintSettings
{
    public function __construct(
        public UuidInterface $variantId,
        public float $bleedMm,
        public bool $cropMarks,
    ) {
    }
}
