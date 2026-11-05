<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use WBoost\Web\Entity\ProjectColor;

/**
 * What "Sloučit" (F7 P4) would do when `survivor` stays and `losers` merge
 * into it — the confirm dialog shows one per possible survivor. Built by
 * {@see \WBoost\Web\Services\Palette\PaletteColorMerge::preview()}, the same
 * fill-the-gap rule the writer applies.
 */
readonly final class PaletteMergePreview
{
    /**
     * @param list<ProjectColor> $losers palette order
     * @param array{cmyk: null|array{null|string, null|string, null|string, null|string}, pantone: null|string, ral: null|string, name: null|string} $merged the survivor's fields after the merge
     * @param list<string> $gained fields the survivor gains from a loser, e.g. "Pantone 2299 C"
     * @param list<array{manual: string, changes: list<string>}> $manualChanges manuals whose colour changes, by name
     * @param null|string $printCmykBefore the CMYK template print export uses for the HEX today (first linked colour with CMYK, palette order), null = ICC conversion
     * @param null|string $printCmykAfter the same after the merge
     */
    public function __construct(
        public ProjectColor $survivor,
        public array $losers,
        public array $merged,
        public array $gained,
        public array $manualChanges,
        public null|string $printCmykBefore = null,
        public null|string $printCmykAfter = null,
    ) {
    }

    /** Template print PDFs convert the HEX to other ink values after the merge. */
    public function changesTemplatePrint(): bool
    {
        return $this->printCmykBefore !== $this->printCmykAfter;
    }
}
