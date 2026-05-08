<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Positional override maps for a render call. Keys are textbox indexes, which
 * match `canvas.getObjects('textbox')[i]` ↔ `variant.inputs[i]` (the same
 * positional binding the editor uses).
 */
readonly final class ResolvedInputOverrides
{
    /**
     * @param array<int, string> $texts Index → replacement text.
     * @param array<int, bool> $hidden Index → true when the textbox should be hidden.
     */
    public function __construct(
        public array $texts,
        public array $hidden,
    ) {
    }
}
