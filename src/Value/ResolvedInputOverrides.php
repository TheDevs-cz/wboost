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
     * @param array<string, string> $texts inputId → replacement text.
     * @param array<string, bool> $hidden inputId → true when the textbox should be hidden.
     */
    public function __construct(
        public array $texts,
        public array $hidden,
    ) {
    }
}
