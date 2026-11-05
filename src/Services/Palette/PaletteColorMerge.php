<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Palette;

use WBoost\Web\Entity\ProjectColor;
use WBoost\Web\Query\PaletteColorUsageRow;
use WBoost\Web\Value\ManualColorType;
use WBoost\Web\Value\PaletteMergePreview;

/**
 * The rules of "Sloučit" (F7 P4, plan §6 D1 / §7) — pure, shared by the
 * confirm dialog ({@see preview()}) and {@see ProjectPaletteWriter::mergeColors()}.
 *
 *  - Duplicates = palette colours of one project with the same `hex_key`
 *    (the backfill kept same-HEX colours apart only when two FILLED print
 *    values disagree — §7; "Přidat barvu" can also create one).
 *  - "Fill the gap": the survivor keeps every filled field; an empty CMYK
 *    (the whole tuple, as in the backfill), Pantone, RAL or Název takes the
 *    first filled value of the losers in palette order. Role and HEX spelling
 *    stay the survivor's (HEX keys are equal by definition).
 *  - Default survivor = the colour the most manuals use (fewest manuals
 *    change), then the one with more filled fields, then the earlier palette
 *    position.
 *
 * @phpstan-type Cmyk array{null|string, null|string, null|string, null|string}
 * @phpstan-type MergeFields array{cmyk: null|Cmyk, pantone: null|string, ral: null|string, name: null|string}
 */
final class PaletteColorMerge
{
    /** Merged fields in dialog order, with their Czech labels. */
    public const array FIELDS = ['cmyk' => 'CMYK', 'pantone' => 'Pantone', 'ral' => 'RAL', 'name' => 'Název'];

    /**
     * @param MergeFields $survivor
     * @param list<MergeFields> $losers palette order
     * @return MergeFields
     */
    public static function mergedFields(array $survivor, array $losers): array
    {
        $merged = $survivor;

        foreach (array_keys(self::FIELDS) as $field) {
            if (!self::isEmpty($merged[$field])) {
                continue;
            }

            foreach ($losers as $loser) {
                if (!self::isEmpty($loser[$field])) {
                    $merged[$field] = $loser[$field];
                    break;
                }
            }
        }

        return $merged;
    }

    /** Null, a blank string or an all-blank CMYK tuple. */
    public static function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_array($value)) {
            return implode('', array_map(static fn (mixed $channel): string => is_scalar($channel) ? trim((string) $channel) : '', $value)) === '';
        }

        return !is_scalar($value) || trim((string) $value) === '';
    }

    /**
     * @return MergeFields
     */
    public static function fieldsOf(ProjectColor $color): array
    {
        return ['cmyk' => $color->cmyk, 'pantone' => $color->pantone, 'ral' => $color->ral, 'name' => $color->name];
    }

    /**
     * Same-HEX groups of a palette (≥ 2 colours each), keyed by `hex_key`,
     * colours in palette order.
     *
     * @param array<ProjectColor> $colors palette order
     * @return array<string, list<ProjectColor>>
     */
    public static function duplicateGroups(array $colors): array
    {
        $groups = [];

        foreach ($colors as $color) {
            $groups[strtolower($color->hexKey)][] = $color;
        }

        return array_filter($groups, static fn (array $members): bool => count($members) > 1);
    }

    /**
     * @param list<ProjectColor> $members palette order
     * @param array<string, list<PaletteColorUsageRow>> $usage palette colour id → manuals
     */
    public static function defaultSurvivor(array $members, array $usage): ProjectColor
    {
        $best = $members[0];

        foreach ($members as $member) {
            if (self::rank($member, $usage) > self::rank($best, $usage)) {
                $best = $member;
            }
        }

        return $best;
    }

    /**
     * What merging `$members` into `$survivor` changes — for the dialog.
     *
     * @param list<ProjectColor> $members the whole same-HEX group, palette order
     * @param array<string, list<PaletteColorUsageRow>> $usage palette colour id → manuals
     */
    public static function preview(ProjectColor $survivor, array $members, array $usage): PaletteMergePreview
    {
        $losers = array_values(array_filter($members, static fn (ProjectColor $color): bool => !$color->id->equals($survivor->id)));
        $before = self::fieldsOf($survivor);
        $merged = self::mergedFields($before, array_map(self::fieldsOf(...), $losers));

        $gained = [];
        foreach (self::FIELDS as $field => $label) {
            if (self::isEmpty($before[$field]) && !self::isEmpty($merged[$field])) {
                $gained[] = $label . ' ' . self::format($merged[$field]);
            }
        }

        /** @var array<string, array{name: string, uses: list<array{color: ProjectColor, fromLogo: bool}>}> $manuals */
        $manuals = [];
        foreach ($members as $member) {
            foreach ($usage[$member->id->toString()] ?? [] as $row) {
                $manuals[$row->manualId]['name'] = $row->manualName;
                $manuals[$row->manualId]['uses'][] = ['color' => $member, 'fromLogo' => $row->fromLogo];
            }
        }

        $manualChanges = [];

        foreach ($manuals as $manual) {
            $uses = $manual['uses'];
            $changes = [];

            // Two links in one manual collapse into one unless all are logo
            // links (each logo literal stays — recolouring needs it).
            if (count($uses) > 1 && array_filter($uses, static fn (array $use): bool => !$use['fromLogo']) !== []) {
                $changes[] = 'barva v něm zůstane jen jednou';
            }

            $usesSurvivor = array_filter($uses, static fn (array $use): bool => $use['color']->id->equals($survivor->id)) !== [];

            if ($usesSurvivor) {
                // It already shows the survivor: only the filled gaps change.
                foreach (self::FIELDS as $field => $label) {
                    if (self::isEmpty($before[$field]) && !self::isEmpty($merged[$field])) {
                        $changes[] = $label . ' – → ' . self::format($merged[$field]);
                    }
                }
            } else {
                $loser = $uses[0]['color'];
                $from = self::fieldsOf($loser);

                foreach (self::FIELDS as $field => $label) {
                    if (self::format($from[$field]) !== self::format($merged[$field])) {
                        $changes[] = $label . ' ' . self::format($from[$field]) . ' → ' . self::format($merged[$field]);
                    }
                }

                if ($loser->role !== $survivor->role) {
                    $changes[] = 'role ' . self::roleLabel($loser->role) . ' → ' . self::roleLabel($survivor->role);
                }

                // An added colour prints the palette HEX; a logo colour keeps its literal.
                $added = array_filter($uses, static fn (array $use): bool => !$use['fromLogo']) !== [];
                if ($added && strtolower($loser->hex) !== strtolower($survivor->hex)) {
                    $changes[] = 'HEX #' . strtoupper($loser->hex) . ' → #' . strtoupper($survivor->hex);
                }
            }

            if ($changes !== []) {
                $manualChanges[] = ['manual' => $manual['name'], 'changes' => $changes];
            }
        }

        $collator = new \Collator('cs_CZ');
        usort($manualChanges, static fn (array $a, array $b): int => (int) $collator->compare($a['manual'], $b['manual']));

        // Template print (BrandCmykPalette): the first LINKED colour of the HEX
        // with a CMYK wins, palette order; afterwards the survivor's merged one.
        $printBefore = null;
        $linked = false;
        foreach ($members as $member) {
            if (($usage[$member->id->toString()] ?? []) === []) {
                continue;
            }

            $linked = true;
            if ($printBefore === null && !self::isEmpty($member->cmyk)) {
                $printBefore = self::format($member->cmyk);
            }
        }
        $printAfter = $linked && !self::isEmpty($merged['cmyk']) ? self::format($merged['cmyk']) : null;

        return new PaletteMergePreview($survivor, $losers, $merged, $gained, $manualChanges, $printBefore, $printAfter);
    }

    /** "45/0/95/0", "2299 C", "–" for empty. */
    public static function format(mixed $value): string
    {
        if (self::isEmpty($value)) {
            return '–';
        }

        if (is_array($value)) {
            return implode('/', array_map(static fn (mixed $channel): string => is_scalar($channel) && trim((string) $channel) !== '' ? trim((string) $channel) : '–', $value));
        }

        return is_scalar($value) ? trim((string) $value) : '–';
    }

    private static function roleLabel(null|ManualColorType $role): string
    {
        return match ($role) {
            ManualColorType::Primary => 'Primární',
            ManualColorType::Secondary => 'Sekundární',
            null => 'Bez role',
        };
    }

    /**
     * Comparable rank for {@see defaultSurvivor()}: manuals, filled fields,
     * then earlier position (negated).
     *
     * @param array<string, list<PaletteColorUsageRow>> $usage
     * @return array{int, int, int}
     */
    private static function rank(ProjectColor $color, array $usage): array
    {
        $filled = count(array_filter(self::fieldsOf($color), static fn (mixed $value): bool => !self::isEmpty($value)));

        return [count($usage[$color->id->toString()] ?? []), $filled, -$color->position];
    }
}
