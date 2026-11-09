<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

/**
 * One materialised row (`index` 0 = the template row): its container id and
 * `ids` — template member id → this row's id of that member (the row
 * container itself included, so the map is never empty).
 */
final readonly class TemplateVariantRepeatableRowSlotResponse
{
    /**
     * @param array<string, string> $ids
     */
    public function __construct(
        public int $index,
        public string $containerId,
        public array $ids,
    ) {
    }
}
