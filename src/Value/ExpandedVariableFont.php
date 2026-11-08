<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A variable font expanded into static cuts — one per distinct named
 * instance, in the font's own (fvar) order. `directory` holds the files;
 * VariableFontExpander::discard() removes it.
 */
readonly final class ExpandedVariableFont
{
    /**
     * @param non-empty-list<ExpandedFontFace> $faces
     */
    public function __construct(
        public string $family,
        public string $directory,
        public array $faces,
    ) {
    }
}
