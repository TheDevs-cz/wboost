<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * What {@see \WBoost\Web\Services\Palette\PaletteBackfillPlanner::plan()} decides for ONE project.
 */
readonly final class PaletteProjectPlan
{
    /**
     * @param list<array{position: int, hex: string, hexKey: null|string, cmyk: null|array{null|string, null|string, null|string, null|string}, pantone: null|string, ral: null|string, role: null|string}> $palette
     *   planned `project_color` rows in palette order
     * @param list<array{manualId: string, palettePosition: int, logoHex: null|string, position: int}> $links
     *   planned `manual_palette_color` rows (logoHex non-null = "Z logotypu")
     * @param list<array{type: string, manualId: string, manual: string, hex: string, detail: string}> $findings
     * @param list<array{manualId: string, manual: string, primaryBefore: list<string>, primaryAfter: list<string>, secondaryBefore: list<string>, secondaryAfter: list<string>}> $roleChanges
     *   manuals whose primary / secondary colour pages would change (normalised hex lists)
     * @param list<string> $computeColorsBefore
     * @param list<string> $computeColorsAfter
     * @param array<string, array{float, float, float, float}> $brandCmykBefore
     * @param array<string, array{float, float, float, float}> $brandCmykAfter
     */
    public function __construct(
        public array $palette,
        public array $links,
        public array $findings,
        public array $roleChanges,
        public array $computeColorsBefore,
        public array $computeColorsAfter,
        public array $brandCmykBefore,
        public array $brandCmykAfter,
        public null|string $avatarHexBefore,
        public null|string $avatarHexAfter,
    ) {
    }

    /**
     * @return list<array{type: string, manualId: string, manual: string, hex: string, detail: string}>
     */
    public function findingsOfType(string $type): array
    {
        return array_values(array_filter($this->findings, static fn (array $finding): bool => $finding['type'] === $type));
    }
}
