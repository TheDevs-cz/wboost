<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

/**
 * Capabilities of a CHECKLIST component input (present exactly when the
 * designer added the input as a dedicated checkbox list). The input's value
 * is the plain checkbox-list envelope (`lines` of 'cb'/'cbx'), but instead
 * of a free-form WYSIWYG a consumer should render a fixed per-item editor —
 * one row per line with a checkbox — and honor these flags:
 *
 * - `toggle`: the user may check/uncheck items,
 * - `editText`: the user may edit item texts,
 * - `addItems` / `removeItems`: the user may add/remove rows.
 *
 * The flags are a UI contract; the render accepts any valid checkbox-list
 * value — EXCEPT when all four are false, in which case the input is
 * read-only and provided overrides are ignored (the sample renders).
 */
final readonly class TemplateVariantChecklistResponse
{
    public function __construct(
        public bool $toggle,
        public bool $editText,
        public bool $addItems,
        public bool $removeItems,
    ) {
    }
}
