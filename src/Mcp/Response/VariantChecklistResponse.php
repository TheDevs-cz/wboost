<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * The capabilities of a CHECKLIST input — an input the designer added as one
 * dedicated checkbox list rather than as free text. Its presence (a non-null
 * `checklist` on {@see VariantTextInputResponse}) is what identifies the
 * component; the four flags say what the person filling it may change.
 *
 * They are a UI contract with ONE server-side exception: all four false makes
 * the input read-only, and any value sent for it is ignored in favour of the
 * designer's sample. Everything else is enforced by the surface doing the
 * editing, so an agent that writes a value should respect them rather than
 * discover them.
 */
readonly final class VariantChecklistResponse
{
    public function __construct(
        public bool $toggle,
        public bool $editText,
        public bool $addItems,
        public bool $removeItems,
    ) {
    }
}
