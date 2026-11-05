<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Which templates of a project use which HEX — the "N šablon" chip of the
 * Paleta barev cards and the template sentence of their delete dialog
 * (F7 P4). Built by {@see \WBoost\Web\Query\GetPaletteTemplateUsage}.
 *
 * Keyed by the normalised `#rrggbb` (`project_color.hex_key`): templates
 * store colours as HEX snapshots (D4), never as a palette colour id or a
 * `color(n)` index, so two palette colours of the same HEX (a split
 * duplicate) report the same templates.
 */
readonly final class PaletteTemplateUsage
{
    /**
     * @param array<string, list<string>> $templatesByHexKey `#rrggbb` → template (or document) names, sorted
     */
    public function __construct(
        private array $templatesByHexKey = [],
    ) {
    }

    /**
     * @return list<string> template names, sorted; [] when no template uses the HEX
     */
    public function templatesFor(string $hexKey): array
    {
        return $this->templatesByHexKey[strtolower($hexKey)] ?? [];
    }

    /** "3 šablony" for the HEX, null when no template uses it. */
    public function label(string $hexKey): null|string
    {
        $count = count($this->templatesFor($hexKey));

        return $count === 0 ? null : self::countLabel($count);
    }

    /** Czech plural: 1 šablona, 2–4 šablony, 0 / 5+ šablon. */
    public static function countLabel(int $count): string
    {
        return $count . ' ' . match (true) {
            $count === 1 => 'šablona',
            $count >= 2 && $count <= 4 => 'šablony',
            default => 'šablon',
        };
    }
}
