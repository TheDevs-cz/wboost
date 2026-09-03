<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One template variant that references a font face — where a designer goes
 * to change it (the group editor for grouped variants, the variant editor
 * otherwise).
 */
readonly final class FontUsageSite
{
    public function __construct(
        public string $variantId,
        public string $templateId,
        public string $templateName,
        public string $dimensionLabel,
        public null|string $groupId,
    ) {
    }
}
