<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Palette;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetManuals;
use WBoost\Web\Value\CmykColor;
use WBoost\Web\Value\Color;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\PaletteParityDiff;
use WBoost\Web\Value\RichText;

/**
 * Compares, per project, the colour read model R1 code builds from the manual
 * JSONB columns ({@see JsonbManualColors} — the pre-R2 `Manual` methods and
 * swatch / print-CMYK walk) with the read model R2 serves from the palette
 * tables (logo links → `logo_hex`, custom links → palette HEX; type = the
 * palette role; CMYK/Pantone/RAL from the palette; swatches + print CMYK =
 * the LINKED palette colours in palette order, {@see \WBoost\Web\Query\GetProjectPalette}).
 * Read-only.
 *
 * Every difference is classified ({@see PaletteParityDiff::ACCEPTED_KINDS}).
 * Since R2 the JSONB is a mirror of the links, so after
 * `app:palette:reconcile` a manual has NO per-manual difference at all; one
 * that has any (even an accepted kind) is additionally reported as
 * `jsonb_mirror_stale` — R1 code wrote it (blue-green overlap / rollback) or
 * the R2 mirror never ran — and reconcile repairs it.
 *
 * @phpstan-type PaletteRow array{id: string, project_id: string, hex: string, hex_key: string, role: null|string, cmyk: null|array{null|string, null|string, null|string, null|string}, pantone: null|string, ral: null|string, position: int}
 * @phpstan-type LinkRow array{manual_id: string, project_color_id: string, logo_hex: null|string, position: int}
 * @phpstan-type LinkColor array{hex: string, logo: bool, role: null|string, cmyk: null|array{null|string, null|string, null|string, null|string}, pantone: null|string, ral: null|string, paletteId: string}
 */
readonly final class VerifyPalette
{
    public function __construct(
        private Connection $connection,
        private GetManuals $getManuals,
    ) {
    }

    /**
     * @return array{diffs: list<PaletteParityDiff>, palette: int, logoLinks: int, customLinks: int, staleManuals: array<string, string>}
     */
    public function project(Project $project): array
    {
        $projectId = $project->id->toString();
        $manuals = $this->getManuals->allForProject($project->id);
        $palette = $this->paletteRows($project->id);
        $links = $this->linkRows($project->id);

        $diffs = [];
        $logoLinks = 0;
        $customLinks = 0;
        /** @var array<string, true> $usedPaletteIds */
        $usedPaletteIds = [];

        foreach ($links as $manualLinks) {
            foreach ($manualLinks as $link) {
                $usedPaletteIds[$link['project_color_id']] = true;
                $link['logo_hex'] !== null ? $logoLinks++ : $customLinks++;
            }
        }

        /** @var array<string, string> $staleManuals manual id → name */
        $staleManuals = [];

        foreach ($manuals as $manual) {
            $manualDiffs = $this->manual($manual, $palette, $links[$manual->id->toString()] ?? []);

            foreach ($manualDiffs as [$kind, $detail]) {
                $diffs[] = new PaletteParityDiff($projectId, $project->name, $manual->id->toString(), $manual->name, $kind, $detail);
            }

            if ($manualDiffs !== []) {
                $staleManuals[$manual->id->toString()] = $manual->name;
                $diffs[] = new PaletteParityDiff($projectId, $project->name, $manual->id->toString(), $manual->name, 'jsonb_mirror_stale', sprintf('%d differences between the JSONB mirror and the links — app:palette:reconcile', count($manualDiffs)));
            }
        }

        foreach ($this->projectLevel($manuals, $palette, $usedPaletteIds) as [$kind, $detail]) {
            $diffs[] = new PaletteParityDiff($projectId, $project->name, null, null, $kind, $detail);
        }

        return ['diffs' => $diffs, 'palette' => count($palette), 'logoLinks' => $logoLinks, 'customLinks' => $customLinks, 'staleManuals' => $staleManuals];
    }

    /**
     * @param array<string, PaletteRow> $palette
     * @param list<LinkRow> $links
     * @return list<array{string, string}>
     */
    private function manual(Manual $manual, array $palette, array $links): array
    {
        $diffs = [];

        try {
            $detected = JsonbManualColors::detected($manual);
            $custom = JsonbManualColors::custom($manual);
            $primary = JsonbManualColors::primary($manual);
            $secondary = JsonbManualColors::secondary($manual);
        } catch (\Throwable $e) {
            return [['jsonb_unreadable', $e::class . ': ' . $e->getMessage()]];
        }

        /** @var list<LinkColor> $logoColors */
        $logoColors = [];
        /** @var list<LinkColor> $customColors */
        $customColors = [];
        $expectedPosition = 0;

        foreach ($links as $link) {
            if ($link['position'] !== $expectedPosition) {
                $diffs[] = ['link_positions', sprintf('link position %d where %d was expected', $link['position'], $expectedPosition)];
            }
            $expectedPosition = $link['position'] + 1;

            $color = $palette[$link['project_color_id']] ?? null;

            if ($color === null || $color['project_id'] !== $manual->project->id->toString()) {
                $diffs[] = ['link_foreign_palette', sprintf('position %d links palette colour %s of another project', $link['position'], $link['project_color_id'])];
                continue;
            }

            $linkColor = [
                'hex' => $link['logo_hex'] ?? $color['hex'],
                'logo' => $link['logo_hex'] !== null,
                'role' => $color['role'],
                'cmyk' => $color['cmyk'],
                'pantone' => $color['pantone'],
                'ral' => $color['ral'],
                'paletteId' => $color['id'],
            ];

            if ($linkColor['logo'] && $customColors !== []) {
                $diffs[] = ['segment_order', sprintf('logo link at position %d after an added colour', $link['position'])];
            }

            if ($linkColor['logo']) {
                $logoColors[] = $linkColor;
            } else {
                $customColors[] = $linkColor;
            }
        }

        if (count($detected) !== count($logoColors)) {
            $diffs[] = ['detected_count', sprintf('detectedColors() has %d, logo links %d', count($detected), count($logoColors))];
        }

        if (count($custom) !== count($customColors)) {
            $diffs[] = ['custom_count', sprintf('customColors has %d, custom links %d', count($custom), count($customColors))];
        }

        $hasRoleDiff = false;

        foreach ([[$detected, $logoColors, 'detected'], [$custom, $customColors, 'custom']] as [$jsonbColors, $linkColors, $segment]) {
            foreach ($jsonbColors as $index => $jsonbColor) {
                $linkColor = $linkColors[$index] ?? null;

                if ($linkColor === null) {
                    continue;
                }

                $label = sprintf('%s #%d', $segment, $index + 1);

                if ($jsonbColor->color->hex !== $linkColor['hex']) {
                    if ($segment === 'detected') {
                        $diffs[] = ['logo_hex', sprintf('%s: "%s" vs logo_hex "%s"', $label, $jsonbColor->color->hex, $linkColor['hex'])];
                    } elseif (RichText::normalizeHexColor($jsonbColor->color->hex) === RichText::normalizeHexColor($linkColor['hex'])) {
                        $diffs[] = ['custom_hex_spelling', sprintf('%s: "%s" prints as "%s"', $label, $jsonbColor->color->hex, $linkColor['hex'])];
                    } else {
                        $diffs[] = ['custom_hex', sprintf('%s: "%s" vs palette "%s"', $label, $jsonbColor->color->hex, $linkColor['hex'])];
                    }
                }

                if ($jsonbColor->type?->value !== $linkColor['role']) {
                    $hasRoleDiff = true;
                    $diffs[] = ['role', sprintf('%s %s: %s → %s', $label, $jsonbColor->color->hex, $jsonbColor->type->value ?? 'none', $linkColor['role'] ?? 'none')];
                }

                foreach (self::printDiffs($jsonbColor, $linkColor) as [$kind, $detail]) {
                    $diffs[] = [$kind, sprintf('%s %s: %s', $label, $jsonbColor->color->hex, $detail)];
                }
            }
        }

        // The customer-manual colour pages (primary / secondary).
        $hasStale = false;
        foreach ([['primary', $primary], ['secondary', $secondary]] as [$role, $jsonbPage]) {
            $typedInList = 0;
            foreach ([...$detected, ...$custom] as $jsonbColor) {
                if ($jsonbColor->type?->value === $role) {
                    $typedInList++;
                }
            }
            $hasStale = $hasStale || count($jsonbPage) !== $typedInList;
        }

        foreach ([['primary', $primary], ['secondary', $secondary]] as [$role, $jsonbPage]) {
            $before = array_map(static fn (ManualColor $color): string => RichText::normalizeHexColor($color->color->hex) ?? $color->color->hex, $jsonbPage);
            $after = [];

            foreach ([...$logoColors, ...$customColors] as $linkColor) {
                if ($linkColor['role'] === $role) {
                    $after[] = RichText::normalizeHexColor($linkColor['hex']) ?? $linkColor['hex'];
                }
            }

            if ($before !== $after) {
                $diffs[] = [
                    $hasRoleDiff || $hasStale ? 'role_pages' : 'role_pages_unexplained',
                    sprintf('%s page: %s → %s', $role, $before === [] ? '–' : implode(',', $before), $after === [] ? '–' : implode(',', $after)),
                ];
            }
        }

        return $diffs;
    }

    /**
     * Swatches + print CMYK: the JSONB walk vs the LINKED palette colours in
     * palette order (what R2 serves); palette colours no manual links are
     * listed as `unused_palette_colour` (informational).
     *
     * @param array<Manual> $manuals
     * @param array<string, PaletteRow> $palette
     * @param array<string, true> $usedPaletteIds
     * @return list<array{string, string}>
     */
    private function projectLevel(array $manuals, array $palette, array $usedPaletteIds): array
    {
        $diffs = [];

        try {
            $jsonbColors = JsonbManualColors::computeColors($manuals);
            $jsonbCmyk = JsonbManualColors::brandCmyk($manuals);
            $ordered = JsonbManualColors::ordered($manuals);
        } catch (\Throwable $e) {
            return [['jsonb_unreadable', 'project colours: ' . $e::class . ': ' . $e->getMessage()]];
        }

        $paletteColors = [];
        /** @var list<PaletteRow> $linked */
        $linked = [];

        foreach ($palette as $row) {
            if (!isset($usedPaletteIds[$row['id']])) {
                $diffs[] = ['unused_palette_colour', sprintf('%s (%s) — no manual links it; not a swatch', $row['hex'], $row['hex_key'])];
                continue;
            }

            $linked[] = $row;
            $normalized = RichText::normalizeHexColor($row['hex']);

            if ($normalized !== null && !in_array($normalized, $paletteColors, true)) {
                $paletteColors[] = $normalized;
            }
        }

        if ($jsonbColors !== $paletteColors) {
            foreach (array_diff($jsonbColors, $paletteColors) as $missing) {
                $diffs[] = ['compute_colors_missing', sprintf('%s is a swatch in the JSONB read model but no linked palette colour', $missing)];
            }

            foreach (array_diff($paletteColors, $jsonbColors) as $extra) {
                $diffs[] = ['compute_colors_extra', sprintf('%s is a linked palette colour but not in the JSONB read model', $extra)];
            }

            $common = array_values(array_intersect($paletteColors, $jsonbColors));
            if (array_values(array_intersect($jsonbColors, $paletteColors)) !== $common) {
                $diffs[] = ['palette_order', sprintf('swatches %s → %s', implode(',', $jsonbColors), implode(',', $paletteColors))];
            }
        }

        // BrandCmykPalette, over the linked palette in order (first CMYK wins).
        $paletteCmyk = [];

        foreach ($linked as $row) {
            $normalized = RichText::normalizeHexColor($row['hex']);

            if ($normalized === null || isset($paletteCmyk[$normalized]) || !Color::isValidHex($row['hex'])) {
                continue;
            }

            $cmyk = CmykColor::fromManualColor(new ManualColor(new Color($row['hex']), null, $row['pantone'], $row['cmyk'], $row['ral']));

            if ($cmyk !== null) {
                $paletteCmyk[$normalized] = $cmyk;
            }
        }

        /** @var array<string, array<string, true>> $jsonbCmykVariants */
        $jsonbCmykVariants = [];
        foreach ($ordered as $manualColor) {
            $normalized = RichText::normalizeHexColor($manualColor->color->hex);
            $cmyk = CmykColor::fromManualColor($manualColor);

            if ($normalized !== null && $cmyk !== null) {
                $jsonbCmykVariants[$normalized][implode('/', [$cmyk->c, $cmyk->m, $cmyk->y, $cmyk->k])] = true;
            }
        }

        foreach ($jsonbCmyk as $hex => $cmyk) {
            if (!isset($paletteCmyk[$hex])) {
                $diffs[] = ['brand_cmyk_missing', sprintf('%s has print CMYK in the JSONB read model but none in the linked palette', $hex)];
                continue;
            }

            if ($paletteCmyk[$hex] != $cmyk) {
                $diffs[] = [
                    count($jsonbCmykVariants[$hex] ?? []) > 1 ? 'cmyk_first_wins_order' : 'brand_cmyk_value',
                    sprintf('%s CMYK %s → %s', $hex, self::cmykLabel($cmyk), self::cmykLabel($paletteCmyk[$hex])),
                ];
            }
        }

        foreach ($paletteCmyk as $hex => $cmyk) {
            if (!isset($jsonbCmyk[$hex])) {
                $diffs[] = ['brand_cmyk_extra', sprintf('%s has print CMYK %s only in the linked palette', $hex, self::cmykLabel($cmyk))];
            }
        }

        return $diffs;
    }

    /**
     * @param LinkColor $linkColor
     * @return list<array{string, string}>
     */
    private static function printDiffs(ManualColor $jsonbColor, array $linkColor): array
    {
        $diffs = [];
        $jsonb = PaletteBackfillPlanner::printOf(['cmyk' => $jsonbColor->cmyk, 'pantone' => $jsonbColor->pantone, 'ral' => $jsonbColor->ral]);
        $link = PaletteBackfillPlanner::printOf($linkColor);

        foreach (['cmyk', 'pantone', 'ral'] as $field) {
            $before = $jsonb[$field];
            $after = $link[$field];

            if ($before === $after) {
                continue;
            }

            $label = static fn (null|array|string $value): string => $value === null ? '–' : (is_array($value) ? implode('/', array_map(static fn (mixed $c): string => is_string($c) ? $c : '∅', $value)) : $value);
            $trimmed = static fn (array|string $value): string => is_array($value) ? implode('|', array_map(static fn (mixed $c): string => is_string($c) ? trim($c) : '', $value)) : trim($value);

            if ($before === null) {
                $diffs[] = ['print_gap_filled', sprintf('%s – → %s', $field, $label($after))];
            } elseif ($after !== null && $trimmed($before) === $trimmed($after)) {
                $diffs[] = ['print_whitespace', sprintf('%s "%s" → "%s"', $field, $label($before), $label($after))];
            } else {
                $diffs[] = ['print_data', sprintf('%s %s → %s', $field, $label($before), $label($after))];
            }
        }

        return $diffs;
    }

    private static function cmykLabel(CmykColor $cmyk): string
    {
        return implode('/', [$cmyk->c, $cmyk->m, $cmyk->y, $cmyk->k]);
    }

    /**
     * @return array<string, PaletteRow> by id, in palette order
     */
    private function paletteRows(UuidInterface $projectId): array
    {
        $rows = [];

        /** @var list<array{id: string, project_id: string, hex: string, hex_key: string, role: null|string, cmyk: null|string, pantone: null|string, ral: null|string, position: int|string}> $result */
        $result = $this->connection->fetchAllAssociative(
            'SELECT id, project_id, hex, hex_key, role, cmyk, pantone, ral, position FROM project_color WHERE project_id = ? ORDER BY position, id',
            [$projectId->toString()],
        );

        foreach ($result as $row) {
            $cmyk = $row['cmyk'] === null ? null : json_decode($row['cmyk'], true);
            $channels = is_array($cmyk) ? array_values($cmyk) : null;
            $channel = static fn (int $i): null|string => $channels !== null && is_scalar($channels[$i] ?? null) ? (string) $channels[$i] : null;

            $rows[$row['id']] = [
                'id' => $row['id'],
                'project_id' => $row['project_id'],
                'hex' => $row['hex'],
                'hex_key' => $row['hex_key'],
                'role' => $row['role'],
                'cmyk' => $channels === null ? null : [$channel(0), $channel(1), $channel(2), $channel(3)],
                'pantone' => $row['pantone'],
                'ral' => $row['ral'],
                'position' => (int) $row['position'],
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, list<LinkRow>> manual id → links in position order
     */
    private function linkRows(UuidInterface $projectId): array
    {
        $links = [];

        /** @var list<array{manual_id: string, project_color_id: string, logo_hex: null|string, position: int|string}> $result */
        $result = $this->connection->fetchAllAssociative(
            'SELECT l.manual_id, l.project_color_id, l.logo_hex, l.position FROM manual_palette_color l JOIN manual m ON m.id = l.manual_id WHERE m.project_id = ? ORDER BY l.manual_id, l.position, l.id',
            [$projectId->toString()],
        );

        foreach ($result as $row) {
            $links[$row['manual_id']][] = [
                'manual_id' => $row['manual_id'],
                'project_color_id' => $row['project_color_id'],
                'logo_hex' => $row['logo_hex'],
                'position' => (int) $row['position'],
            ];
        }

        return $links;
    }
}
