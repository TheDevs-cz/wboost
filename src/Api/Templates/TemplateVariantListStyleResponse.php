<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

/**
 * RESOLVED list styling of a lists-enabled rich input (admin values with the
 * derived defaults already filled in — consumers never re-derive them). All
 * distances are canvas px in the same space as the input's `frame`:
 * `indent` is the item text's x-offset from the input's left edge (the bullet
 * sits at the left edge, wrapped continuation lines keep the indent —
 * hanging indent), `itemSpacing` the extra gap between items, `blockSpacing`
 * the gap between blocks (paragraph ↔ list ↔ list of the other type).
 * `bullet`: disc | dash | check | image; `bulletImageUrl` is non-null only
 * for `image` and is what the export draws before each `ul` item (numbered
 * items always render their ordinal, e.g. "1.").
 *
 * Checkbox items ('cb'/'cbx' lines, only on inputs with
 * `listCheckboxes: true`) draw `checkboxImageUrl` (unchecked) /
 * `checkboxCheckedImageUrl` (checked) at the line start; a null URL means
 * that state uses the DEFAULT drawn checkbox — a rounded square filled with
 * the item's text color, the checked one with a white check mark.
 */
final readonly class TemplateVariantListStyleResponse
{
    public function __construct(
        public string $bullet,
        public null|string $bulletImageUrl,
        public float $indent,
        public float $itemSpacing,
        public float $blockSpacing,
        public null|string $checkboxImageUrl = null,
        public null|string $checkboxCheckedImageUrl = null,
    ) {
    }
}
