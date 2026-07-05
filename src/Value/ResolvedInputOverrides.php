<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Override maps for a render call, keyed by the canvas object's `inputId`
 * UUID. Replaces the legacy positional binding (`canvas.getObjects('textbox')[i]`
 * ↔ `variant.inputs[i]`) — see Stage 2 of the Fabric migration.
 */
readonly final class ResolvedInputOverrides
{
    /**
     * @param array<string, string> $texts inputId → replacement text. For a
     *   rich-text override this holds the PLAIN concatenation (the projection
     *   maxLength/uppercase were applied to) so every plain-text consumer
     *   keeps working; the styled runs live in $richTexts under the same key.
     * @param array<string, bool> $hidden inputId → true when the textbox should be hidden.
     * @param array<string, RichText> $richTexts inputId → styled runs, present
     *   only for rich-text inputs whose value actually carries styling.
     */
    public function __construct(
        public array $texts,
        public array $hidden,
        public array $richTexts = [],
    ) {
    }
}
