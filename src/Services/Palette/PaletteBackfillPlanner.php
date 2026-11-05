<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Palette;

use WBoost\Web\Value\CmykColor;
use WBoost\Web\Value\Color;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\PaletteProjectPlan;
use WBoost\Web\Value\RichText;

/**
 * Plans the project palette ("Paleta barev", F7) from the manuals' JSONB
 * colours — arrays in, planned palette rows + manual links + a report out.
 * No entity, no database: the dry run (`app:palette:plan`) and the P1a
 * backfill migration feed it the raw columns, so both see the same answer.
 * FROZEN — the P1a migration (`Version20261105170659`, via
 * {@see PaletteBackfillSql}) replays it, and a
 * migration must replay forever. The runtime projector
 * ({@see ProjectManualColors}) reuses its per-manual helpers so the backfill and
 * every later write agree; change neither without a new migration.
 *
 * Rules (`docs/redesign/palette-plan.md` §2 + §7, owner decisions D1 + D2):
 *  1. Each manual's old lists are rebuilt exactly as `Manual` builds them
 *     today: detected = `detectedColors()` (persisted detected filtered to the
 *     logo's literal colours ++ logo colours never persisted), custom as is.
 *  2. Palette ORDER is the `ResolveRichTextOptions::orderedManualColors()`
 *     walk (every manual's primaries, then secondaries, then untyped) over
 *     manuals in `created_at, id` order, so `computeColors()` and
 *     `BrandCmykPalette` stay identical when they iterate the palette.
 *  3. D1 dedupe with the §7 "fill the gap" refinement: per normalised
 *     `#rrggbb`, an occurrence joins the first palette colour that already
 *     carries all its print data (CMYK tuple, Pantone, RAL — compared
 *     trimmed), else the first one none of whose filled values disagree with
 *     it (filling the empty fields: the union), else it starts a new entry.
 *     Two different non-empty values = separate entries (split duplicates,
 *     "Sloučit" in P4). Display hex = first occurrence's literal.
 *  4. D2 role PER PROJECT: every manual that uses a palette colour votes with
 *     the strongest role it gives it (primary > secondary > none); the role
 *     most manuals vote for wins, a tie goes to primary > secondary > none.
 *  5. Links: the rebuilt detected list → logo links (literal `logo_hex`,
 *     position), custom → custom links; one position sequence per manual
 *     (logo segment first), which is what `color(n)` indexes. A logo literal
 *     repeated inside one manual keeps its position but links without
 *     `logo_hex` (UNIQUE (manual_id, logo_hex) — reported).
 *
 * Nothing is auto-fixed — every case the plan changes is a report finding.
 *
 * @phpstan-type Cmyk array{null|string, null|string, null|string, null|string}
 * @phpstan-type RawColor array{color: string, type: null|string, pantone: null|string, ral: null|string, cmyk: null|Cmyk}
 * @phpstan-type ManualInput array{id: string, name: string, brand: bool, logoColors: list<string>, detected: list<RawColor>, custom: list<RawColor>}
 * @phpstan-type Occurrence array{manual: int, source: string, index: int, hex: string, hexKey: null|string, role: null|string, pantone: null|string, ral: null|string, cmyk: null|Cmyk}
 * @phpstan-type PrintData array{cmyk: null|Cmyk, pantone: null|string, ral: null|string}
 * @phpstan-type Segments array{persisted: list<Occurrence>, logo: list<Occurrence>, custom: list<Occurrence>}
 * @phpstan-type Finding array{type: string, manualId: string, manual: string, hex: string, detail: string}
 */
final class PaletteBackfillPlanner
{
    public const string ROLE_PRIMARY = 'primary';
    public const string ROLE_SECONDARY = 'secondary';

    /**
     * Coerces one manual's stored columns (decoded JSON or JSON strings) into
     * planner input. Lenient on purpose: odd values are kept for the planner
     * to REPORT, not silently dropped here.
     *
     * @return ManualInput
     */
    public static function manualFromStorage(
        string $id,
        string $name,
        bool $brand,
        mixed $logo,
        mixed $detectedColors,
        mixed $customColors,
    ): array {
        $logo = self::decode($logo);
        $logoColors = [];

        // Same image order as Logo::getDetectedColors().
        foreach (['horizontal', 'horizontalWithClaim', 'symbol', 'vertical', 'verticalWithClaim'] as $variant) {
            $image = $logo[$variant] ?? null;

            if (!is_array($image) || !is_array($image['detectedColors'] ?? null)) {
                continue;
            }

            foreach ($image['detectedColors'] as $color) {
                if (is_scalar($color)) {
                    $logoColors[] = (string) $color;
                }
            }
        }

        return [
            'id' => $id,
            'name' => $name,
            'brand' => $brand,
            'logoColors' => $logoColors,
            'detected' => self::rawColors($detectedColors),
            'custom' => self::rawColors($customColors),
        ];
    }

    /**
     * @param list<ManualInput> $manuals ordered `created_at, id`
     */
    public static function plan(array $manuals): PaletteProjectPlan
    {
        /** @var list<array{type: string, manualId: string, manual: string, hex: string, detail: string}> $findings */
        $findings = [];

        /** @var array<int, list<Occurrence>> $logoSegments */
        $logoSegments = [];
        /** @var array<int, list<Occurrence>> $customSegments */
        $customSegments = [];
        /** @var array<int, list<Occurrence>> $persistedByManual raw persisted detected, stale included */
        $persistedByManual = [];

        foreach ($manuals as $m => $manual) {
            $segments = self::segments($m, $manual, $findings);
            $persistedByManual[$m] = $segments['persisted'];
            $logoSegments[$m] = $segments['logo'];
            $customSegments[$m] = $segments['custom'];
        }

        // 2. The orderedManualColors walk.
        /** @var list<Occurrence> $walk */
        $walk = [];

        foreach ([self::ROLE_PRIMARY, self::ROLE_SECONDARY] as $role) {
            foreach ($manuals as $m => $manual) {
                foreach ([...$persistedByManual[$m], ...$customSegments[$m]] as $occurrence) {
                    if ($occurrence['role'] === $role) {
                        $walk[] = $occurrence;
                    }
                }
            }
        }

        foreach ($manuals as $m => $manual) {
            foreach ([...$logoSegments[$m], ...$customSegments[$m]] as $occurrence) {
                if ($occurrence['role'] === null) {
                    $walk[] = $occurrence;
                }
            }
        }

        // 3. Dedupe in walk order — D1 + §7 "fill the gap".
        /** @var array<string, int> $positionByKey occurrence id → palette position */
        $positionByKey = [];
        /** @var list<Occurrence> $first first occurrence per palette position */
        $first = [];
        /** @var list<PrintData> $unions the print data each palette position carries */
        $unions = [];
        /** @var array<string, list<int>> $positionsByGroupKey */
        $positionsByGroupKey = [];
        /** @var array<int, list<Occurrence>> $occurrencesByPosition */
        $occurrencesByPosition = [];

        foreach ($walk as $occurrence) {
            $groupKey = self::groupKey($occurrence);
            $print = self::printOf($occurrence);
            $candidates = [];

            foreach ($positionsByGroupKey[$groupKey] ?? [] as $candidate) {
                $candidates[$candidate] = $unions[$candidate];
            }

            $position = self::matchPrint($print, $candidates);

            if ($position === null) {
                $position = count($first);
                $first[] = $occurrence;
                $unions[] = $print;
                $positionsByGroupKey[$groupKey][] = $position;
            } else {
                $unions[$position] = self::unionPrint($unions[$position], $print);
            }

            $positionByKey[self::occurrenceId($occurrence)] = $position;
            $occurrencesByPosition[$position][] = $occurrence;
        }

        // 4. D2 — per-project role by majority of manuals.
        $roles = [];

        foreach ($first as $position => $occurrence) {
            /** @var array<int, int> $strongestByManual manual → rank (0 primary, 1 secondary, 2 none) */
            $strongestByManual = [];

            foreach ($occurrencesByPosition[$position] as $use) {
                $rank = self::rank($use['role']);
                $strongestByManual[$use['manual']] = min($strongestByManual[$use['manual']] ?? 2, $rank);
            }

            $votes = [0, 0, 0];
            foreach ($strongestByManual as $rank) {
                $votes[$rank]++;
            }

            $winner = 0;
            foreach ([1, 2] as $rank) {
                if ($votes[$rank] > $votes[$winner]) {
                    $winner = $rank;
                }
            }

            $roles[$position] = self::roleOf($winner);

            if (count(array_filter($votes)) > 1) {
                $manualNames = [];
                foreach ($strongestByManual as $m => $rank) {
                    $manualNames[] = sprintf('%s=%s', $manuals[$m]['name'], self::roleOf($rank) ?? 'none');
                }

                $findings[] = [
                    'type' => 'role_vote',
                    'manualId' => '',
                    'manual' => '',
                    'hex' => $occurrence['hex'],
                    'detail' => sprintf('votes primary %d / secondary %d / none %d → %s (%s)', $votes[0], $votes[1], $votes[2], self::roleOf($winner) ?? 'none', implode(', ', $manualNames)),
                ];
            }
        }

        /** @var list<array{position: int, hex: string, hexKey: null|string, cmyk: null|Cmyk, pantone: null|string, ral: null|string, role: null|string}> $palette */
        $palette = [];

        foreach ($first as $position => $occurrence) {
            $palette[] = [
                'position' => $position,
                'hex' => $occurrence['hex'],
                'hexKey' => $occurrence['hexKey'],
                'cmyk' => $unions[$position]['cmyk'],
                'pantone' => $unions[$position]['pantone'],
                'ral' => $unions[$position]['ral'],
                'role' => $roles[$position],
            ];

            self::reportMergedLiterals($occurrence, $occurrencesByPosition[$position], $manuals, $findings);
        }

        // Split duplicates: one normalised hex, several palette entries.
        /** @var array<string, list<int>> $positionsByHexKey */
        $positionsByHexKey = [];
        foreach ($palette as $row) {
            $positionsByHexKey[$row['hexKey'] ?? ('raw:' . strtolower($row['hex']))][] = $row['position'];
        }

        foreach ($positionsByHexKey as $positions) {
            if (count($positions) < 2) {
                continue;
            }

            foreach ($positions as $variant => $position) {
                $users = [];
                foreach ($occurrencesByPosition[$position] as $use) {
                    $users[$manuals[$use['manual']]['name']] = true;
                }

                $findings[] = [
                    'type' => 'split_duplicate',
                    'manualId' => '',
                    'manual' => '',
                    'hex' => $palette[$position]['hex'],
                    'detail' => sprintf(
                        'variant %d/%d: %s; used by %s',
                        $variant + 1,
                        count($positions),
                        self::describePrint($palette[$position]['cmyk'], $palette[$position]['pantone'], $palette[$position]['ral']),
                        implode(', ', array_keys($users)),
                    ),
                ];
            }
        }

        // 5. Links + the read model they produce.
        /** @var list<array{manualId: string, palettePosition: int, logoHex: null|string, position: int}> $links */
        $links = [];
        /** @var list<array{manualId: string, manual: string, primaryBefore: list<string>, primaryAfter: list<string>, secondaryBefore: list<string>, secondaryAfter: list<string>}> $roleChanges */
        $roleChanges = [];
        $avatarBefore = null;
        $avatarAfter = null;

        foreach (self::avatarOrder($manuals) as $m) {
            foreach ($persistedByManual[$m] as $occurrence) {
                if ($avatarBefore === null && $occurrence['role'] === self::ROLE_PRIMARY && !self::isWhite($occurrence['hex'])) {
                    $avatarBefore = $occurrence['hex'];
                }
            }

            foreach ($customSegments[$m] as $occurrence) {
                if ($avatarBefore === null && $occurrence['role'] === self::ROLE_PRIMARY && !self::isWhite($occurrence['hex'])) {
                    $avatarBefore = $occurrence['hex'];
                }
            }
        }

        /** @var array<int, list<array{hex: string, role: null|string}>> $newColorsByManual */
        $newColorsByManual = [];

        foreach ($manuals as $m => $manual) {
            $position = 0;
            $seenPositionByHex = [];
            $seenPaletteByHex = [];
            $seenLogoHex = [];
            $newColorsByManual[$m] = [];
            $oldColorN = [];

            foreach ([...$logoSegments[$m], ...$customSegments[$m]] as $occurrence) {
                $palettePosition = $positionByKey[self::occurrenceId($occurrence)];
                $isLogo = $occurrence['source'] !== 'custom';
                $links[] = [
                    'manualId' => $manual['id'],
                    'palettePosition' => $palettePosition,
                    // UNIQUE (manual_id, logo_hex): a repeated literal keeps its position, not the logo mark.
                    'logoHex' => $isLogo && !isset($seenLogoHex[$occurrence['hex']]) ? $occurrence['hex'] : null,
                    'position' => $position,
                ];

                $gained = self::gainedPrint(self::printOf($occurrence), $unions[$palettePosition]);
                if ($gained !== []) {
                    $findings[] = self::finding('print_gap_filled', $manual, $occurrence['hex'], sprintf('color(%d) gains %s from the merged palette colour (§7 fill the gap)', $position + 1, implode(', ', $gained)));
                }

                $newHex = $isLogo ? $occurrence['hex'] : $palette[$palettePosition]['hex'];
                $newColorsByManual[$m][] = ['hex' => $newHex, 'role' => $palette[$palettePosition]['role']];
                $oldColorN[] = $occurrence['hex'];

                if ($newHex !== $occurrence['hex']) {
                    $findings[] = self::finding('custom_hex_literal_change', $manual, $occurrence['hex'], sprintf('color(%d) and the printed HEX become "%s" (palette literal)%s', $position + 1, $newHex, $position < 3 ? ' — position ≤ 3: a default logo-recolouring key' : ''));
                }

                $hexKey = $occurrence['hexKey'] ?? $occurrence['hex'];
                if (isset($seenPositionByHex[$hexKey])) {
                    $findings[] = self::finding('in_manual_duplicate', $manual, $occurrence['hex'], sprintf(
                        'positions %d and %d share a hex (%s)%s',
                        $seenPositionByHex[$hexKey] + 1,
                        $position + 1,
                        $palettePosition === $seenPaletteByHex[$hexKey] ? 'same palette colour, two links' : 'conflicting print data, two palette colours',
                        $isLogo && isset($seenLogoHex[$occurrence['hex']]) ? '; repeated logo literal — UNIQUE (manual_id, logo_hex), linked without logo_hex' : '',
                    ));
                } else {
                    $seenPositionByHex[$hexKey] = $position;
                    $seenPaletteByHex[$hexKey] = $palettePosition;
                }

                if ($isLogo) {
                    $seenLogoHex[$occurrence['hex']] = true;
                }

                $position++;
            }

            $oldPrimary = self::keysWithRole([...$persistedByManual[$m], ...$customSegments[$m]], self::ROLE_PRIMARY);
            $oldSecondary = self::keysWithRole([...$persistedByManual[$m], ...$customSegments[$m]], self::ROLE_SECONDARY);
            $newPrimary = self::keysWithRole($newColorsByManual[$m], self::ROLE_PRIMARY);
            $newSecondary = self::keysWithRole($newColorsByManual[$m], self::ROLE_SECONDARY);

            if ($oldPrimary !== $newPrimary || $oldSecondary !== $newSecondary) {
                $roleChanges[] = [
                    'manualId' => $manual['id'],
                    'manual' => $manual['name'],
                    'primaryBefore' => $oldPrimary,
                    'primaryAfter' => $newPrimary,
                    'secondaryBefore' => $oldSecondary,
                    'secondaryAfter' => $newSecondary,
                ];

                $findings[] = self::finding('role_change', $manual, '', sprintf(
                    'primary: %s → %s | secondary: %s → %s',
                    self::listOrDash($oldPrimary),
                    self::listOrDash($newPrimary),
                    self::listOrDash($oldSecondary),
                    self::listOrDash($newSecondary),
                ));
            }
        }

        foreach (self::avatarOrder($manuals) as $m) {
            foreach ($newColorsByManual[$m] as $color) {
                if ($avatarAfter === null && $color['role'] === self::ROLE_PRIMARY && !self::isWhite($color['hex'])) {
                    $avatarAfter = $color['hex'];
                }
            }
        }

        if ($avatarBefore !== $avatarAfter) {
            $findings[] = [
                'type' => 'avatar_change',
                'manualId' => '',
                'manual' => '',
                'hex' => (string) $avatarAfter,
                'detail' => sprintf('monogram colour %s → %s (only shown when the project has no icon and no logo)', $avatarBefore ?? 'palette fallback', $avatarAfter ?? 'palette fallback'),
            ];
        }

        // Parity: computeColors + BrandCmykPalette over the old walk vs the palette.
        $computeColorsBefore = self::normalizedUnique(array_map(static fn (array $o): string => $o['hex'], $walk));
        $computeColorsAfter = self::normalizedUnique(array_map(static fn (array $row): string => $row['hex'], $palette));
        $cmykBefore = self::brandCmyk($walk, $manuals, $findings);
        $ignored = [];
        $cmykAfter = self::brandCmyk(array_map(
            static fn (array $row): array => self::occurrence(-1, 'palette', $row['position'], $row['hex'], null, $row['pantone'], $row['ral'], $row['cmyk']),
            $palette,
        ), $manuals, $ignored);

        if ($computeColorsBefore !== $computeColorsAfter) {
            $findings[] = ['type' => 'parity_broken', 'manualId' => '', 'manual' => '', 'hex' => '', 'detail' => 'computeColors differs: ' . implode(',', $computeColorsBefore) . ' vs ' . implode(',', $computeColorsAfter)];
        }

        if ($cmykBefore !== $cmykAfter) {
            $findings[] = ['type' => 'parity_broken', 'manualId' => '', 'manual' => '', 'hex' => '', 'detail' => 'BrandCmykPalette differs'];
        }

        return new PaletteProjectPlan(
            palette: $palette,
            links: $links,
            findings: $findings,
            roleChanges: $roleChanges,
            computeColorsBefore: $computeColorsBefore,
            computeColorsAfter: $computeColorsAfter,
            brandCmykBefore: $cmykBefore,
            brandCmykAfter: $cmykAfter,
            avatarHexBefore: $avatarBefore,
            avatarHexAfter: $avatarAfter,
        );
    }

    /**
     * One manual's colours the way `Manual` reads them today: the raw
     * persisted detected list (stale entries included — `primaryColors()`
     * reads them), the rebuilt `detectedColors()` (= the logo segment) and
     * the custom list. Public for the runtime projector, which must split a
     * manual exactly like the backfill did.
     *
     * @param ManualInput $manual
     * @param list<Finding> $findings
     * @return Segments
     */
    public static function segments(int $m, array $manual, array &$findings = []): array
    {
        $persisted = self::occurrences($m, 'detected', $manual['detected'], $manual, $findings);
        $custom = self::occurrences($m, 'custom', $manual['custom'], $manual, $findings);

        // Manual::detectedColors(), step by step — including its LOOSE
        // in_array on the "already listed" check, which is what decides
        // whether a logo colour is appended.
        /** @var list<Occurrence> $detected */
        $detected = [];
        $detectedHex = [];
        $fromLogo = [];

        foreach ($persisted as $occurrence) {
            $detected[] = $occurrence;
            $detectedHex[] = $occurrence['hex'];
        }

        foreach (array_values(array_unique($manual['logoColors'])) as $index => $literal) {
            if (!Color::isValidHex($literal)) {
                $findings[] = self::finding('non_hex_detected', $manual, $literal, 'logo colour is not a hex — new Color() throws, so Manual::detectedColors() fails for this manual today; skipped');
                continue;
            }

            $hex = ltrim($literal, '#');

            if (!in_array($hex, $detectedHex)) {
                $detected[] = self::occurrence($m, 'logo', $index, $hex, null, null, null, null);
                $detectedHex[] = $hex;
            }

            if (!in_array($hex, $fromLogo)) {
                $fromLogo[] = $hex;
            }
        }

        $logo = [];

        foreach ($detected as $occurrence) {
            if (in_array($occurrence['hex'], $fromLogo, true)) {
                $logo[] = $occurrence;
                continue;
            }

            $findings[] = self::finding(
                'stale_detected',
                $manual,
                $occurrence['hex'],
                $occurrence['role'] === null
                    ? 'persisted detected colour not in the logo, untyped — invisible today, dropped'
                    : sprintf('persisted detected colour not in the logo, %s — on the %s page today, has no link after the backfill (stays in the palette)', $occurrence['role'], $occurrence['role']),
            );
        }

        return ['persisted' => $persisted, 'logo' => $logo, 'custom' => $custom];
    }

    /**
     * One manual's share of the palette walk: its typed colours (primaries,
     * then secondaries — persisted detected incl. stale, then custom), then
     * its untyped logo-segment and custom colours.
     *
     * @param Segments $segments
     * @return list<Occurrence>
     */
    public static function manualWalk(array $segments): array
    {
        $walk = [];

        foreach ([self::ROLE_PRIMARY, self::ROLE_SECONDARY] as $role) {
            foreach ([...$segments['persisted'], ...$segments['custom']] as $occurrence) {
                if ($occurrence['role'] === $role) {
                    $walk[] = $occurrence;
                }
            }
        }

        foreach ([...$segments['logo'], ...$segments['custom']] as $occurrence) {
            if ($occurrence['role'] === null) {
                $walk[] = $occurrence;
            }
        }

        return $walk;
    }

    /**
     * The strongest role one manual gives each colour (its D2 "vote"), keyed
     * by {@see groupKey()}: primary > secondary > none (null).
     *
     * @param Segments $segments
     * @return array<string, null|string>
     */
    public static function manualRoles(array $segments): array
    {
        $ranks = [];

        foreach (self::manualWalk($segments) as $occurrence) {
            $key = self::groupKey($occurrence);
            $ranks[$key] = min($ranks[$key] ?? 2, self::rank($occurrence['role']));
        }

        return array_map(self::roleOf(...), $ranks);
    }

    /**
     * Stable identity of an occurrence inside one plan / one manual.
     *
     * @param Occurrence $occurrence
     */
    public static function occurrenceId(array $occurrence): string
    {
        return $occurrence['manual'] . ':' . $occurrence['source'] . ':' . $occurrence['index'];
    }

    /**
     * What palette colours are grouped by: the normalised `#rrggbb`, or the
     * lowercased literal for a hex `computeColors` cannot normalise. Also the
     * value stored in `project_color.hex_key`.
     *
     * @param array{hex: string, hexKey: null|string, ...} $occurrence
     */
    public static function groupKey(array $occurrence): string
    {
        return $occurrence['hexKey'] ?? ('#' . strtolower($occurrence['hex']));
    }

    /**
     * The occurrence's print data with every empty value (null, blank, an
     * all-blank CMYK tuple) as null. Literals are kept, untrimmed.
     *
     * @param array{cmyk: null|Cmyk, pantone: null|string, ral: null|string, ...} $occurrence
     * @return PrintData
     */
    public static function printOf(array $occurrence): array
    {
        $cmyk = $occurrence['cmyk'];

        if ($cmyk !== null && implode('', array_map(static fn (null|string $c): string => trim($c ?? ''), $cmyk)) === '') {
            $cmyk = null;
        }

        return [
            'cmyk' => $cmyk,
            'pantone' => self::textKey($occurrence['pantone']) === '' ? null : $occurrence['pantone'],
            'ral' => self::textKey($occurrence['ral']) === '' ? null : $occurrence['ral'],
        ];
    }

    /**
     * §7 "fill the gap": the first candidate that already carries all of
     * `$print` (no gap to fill), else the first none of whose filled values
     * disagree with it, else null (a new palette colour). Candidates in
     * palette order, keyed by position.
     *
     * @param PrintData $print
     * @param array<int, PrintData> $candidates
     */
    public static function matchPrint(array $print, array $candidates): null|int
    {
        foreach ($candidates as $position => $candidate) {
            if (self::gainedPrint($candidate, $print) === [] && self::printCompatible($candidate, $print)) {
                return $position;
            }
        }

        foreach ($candidates as $position => $candidate) {
            if (self::printCompatible($candidate, $print)) {
                return $position;
            }
        }

        return null;
    }

    /**
     * No field where both sides are filled and differ (compared trimmed).
     *
     * @param PrintData $a
     * @param PrintData $b
     */
    public static function printCompatible(array $a, array $b): bool
    {
        foreach (['cmyk', 'pantone', 'ral'] as $field) {
            if ($a[$field] !== null && $b[$field] !== null && self::printValueKey($a[$field]) !== self::printValueKey($b[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The fields `$union` fills that `$print` leaves empty, as "pantone 2299 C"
     * labels — what a manual gains on its colour page from the merge.
     *
     * @param PrintData $print
     * @param PrintData $union
     * @return list<string>
     */
    public static function gainedPrint(array $print, array $union): array
    {
        $gained = [];

        foreach (['cmyk', 'pantone', 'ral'] as $field) {
            if ($print[$field] === null && $union[$field] !== null) {
                $gained[] = $field . ' ' . (is_array($union[$field]) ? implode('/', array_map(static fn (null|string $c): string => $c ?? '∅', $union[$field])) : $union[$field]);
            }
        }

        return $gained;
    }

    /**
     * @param PrintData $union
     * @param PrintData $print
     * @return PrintData
     */
    public static function unionPrint(array $union, array $print): array
    {
        return [
            'cmyk' => $union['cmyk'] ?? $print['cmyk'],
            'pantone' => $union['pantone'] ?? $print['pantone'],
            'ral' => $union['ral'] ?? $print['ral'],
        ];
    }

    /**
     * @param Cmyk|string $value
     */
    private static function printValueKey(array|string $value): string
    {
        if (is_array($value)) {
            return implode('|', array_map(static fn (null|string $c): string => trim($c ?? ''), $value));
        }

        return trim($value);
    }

    /**
     * @param list<RawColor> $colors
     * @param ManualInput $manual
     * @param list<array{type: string, manualId: string, manual: string, hex: string, detail: string}> $findings
     * @return list<Occurrence>
     */
    private static function occurrences(int $m, string $source, array $colors, array $manual, array &$findings): array
    {
        $occurrences = [];

        foreach ($colors as $index => $raw) {
            $role = $raw['type'];

            if (!Color::isValidHex($raw['color'])) {
                $findings[] = self::finding('invalid_persisted_color', $manual, $raw['color'], sprintf('%s colour #%d is not a hex — Doctrine hydration of this manual throws today; skipped', $source, $index + 1));
                continue;
            }

            if ($role !== null && $role !== self::ROLE_PRIMARY && $role !== self::ROLE_SECONDARY) {
                $findings[] = self::finding('invalid_persisted_color', $manual, $raw['color'], sprintf('%s colour #%d has unknown type "%s" — hydration throws today; treated as untyped', $source, $index + 1, $role));
                $role = null;
            }

            $occurrence = self::occurrence($m, $source, $index, ltrim($raw['color'], '#'), $role, $raw['pantone'], $raw['ral'], $raw['cmyk']);

            if ($occurrence['hexKey'] === null) {
                $findings[] = self::finding('non_normalizable_hex', $manual, $occurrence['hex'], 'valid for Color (3–6 digits) but not #rgb/#rrggbb — computeColors skips it today');
            }

            $occurrences[] = $occurrence;
        }

        return $occurrences;
    }

    /**
     * @param null|Cmyk $cmyk
     * @return Occurrence
     */
    private static function occurrence(int $m, string $source, int $index, string $hex, null|string $role, null|string $pantone, null|string $ral, null|array $cmyk): array
    {
        // ManualColor collapses an all-null tuple to null.
        if ($cmyk === [null, null, null, null]) {
            $cmyk = null;
        }

        $hexKey = RichText::normalizeHexColor($hex);

        return [
            'manual' => $m,
            'source' => $source,
            'index' => $index,
            'hex' => $hex,
            'hexKey' => $hexKey,
            'role' => $role,
            'pantone' => $pantone,
            'ral' => $ral,
            'cmyk' => $cmyk,
        ];
    }

    /**
     * @param Occurrence $first
     * @param list<Occurrence> $uses
     * @param list<ManualInput> $manuals
     * @param list<array{type: string, manualId: string, manual: string, hex: string, detail: string}> $findings
     */
    private static function reportMergedLiterals(array $first, array $uses, array $manuals, array &$findings): void
    {
        $hexLiterals = [];
        $printLiterals = [];

        foreach ($uses as $use) {
            $hexLiterals[$use['hex']][$manuals[$use['manual']]['name']] = true;
            $print = self::printOf($use);

            // Only FILLED values count: empty vs filled is the §7 gap fill
            // (reported per manual as print_gap_filled), not a literal variant.
            foreach (['cmyk', 'pantone', 'ral'] as $field) {
                if ($print[$field] !== null) {
                    $literal = is_array($print[$field]) ? implode('/', array_map(static fn (null|string $c): string => $c ?? '∅', $print[$field])) : $print[$field];
                    $printLiterals[$field][$literal] = true;
                }
            }
        }

        foreach ($printLiterals as $field => $literals) {
            if (count($literals) < 2) {
                unset($printLiterals[$field]);
            }
        }

        if (count($hexLiterals) > 1) {
            $parts = [];
            $lengths = [];
            foreach ($hexLiterals as $literal => $names) {
                $parts[] = sprintf('"%s" (%s)', $literal, implode(', ', array_keys($names)));
                $lengths[strlen((string) $literal)] = true;
            }

            $findings[] = [
                'type' => 'hex_variant_merged',
                'manualId' => '',
                'manual' => '',
                'hex' => $first['hex'],
                'detail' => sprintf('%s merged: %s; palette keeps "%s"; logo links keep their literal, custom links print the palette literal', count($lengths) > 1 ? '3-digit/6-digit' : 'case-only', implode(' + ', $parts), $first['hex']),
            ];
        }

        if (count($printLiterals) > 1) {
            $findings[] = [
                'type' => 'print_literal_variant',
                'manualId' => '',
                'manual' => '',
                'hex' => $first['hex'],
                'detail' => 'same print data up to whitespace merged: ' . implode('; ', array_map(
                    static fn (string $field, array $literals): string => $field . ' "' . implode('" + "', array_map('strval', array_keys($literals))) . '"',
                    array_keys($printLiterals),
                    $printLiterals,
                )),
            ];
        }
    }

    /**
     * BrandCmykPalette::fromManuals(), over an ordered occurrence list. A
     * conflicting CMYK on an already-mapped hex is what it logs today.
     *
     * @param list<Occurrence> $ordered
     * @param list<ManualInput> $manuals
     * @param list<array{type: string, manualId: string, manual: string, hex: string, detail: string}> $findings
     * @return array<string, array{float, float, float, float}>
     */
    private static function brandCmyk(array $ordered, array $manuals, array &$findings): array
    {
        $palette = [];
        $firstManual = [];

        foreach ($ordered as $occurrence) {
            $hex = $occurrence['hexKey'];

            if ($hex === null) {
                continue;
            }

            $cmyk = CmykColor::fromManualColor(new ManualColor(new Color($occurrence['hex']), null, $occurrence['pantone'], $occurrence['cmyk'], $occurrence['ral']));

            if ($cmyk === null) {
                continue;
            }

            $values = [$cmyk->c, $cmyk->m, $cmyk->y, $cmyk->k];

            if (isset($palette[$hex])) {
                if ($palette[$hex] !== $values && $occurrence['manual'] >= 0) {
                    $findings[] = [
                        'type' => 'cmyk_conflict',
                        'manualId' => $manuals[$occurrence['manual']]['id'],
                        'manual' => $manuals[$occurrence['manual']]['name'],
                        'hex' => $hex,
                        'detail' => sprintf('CMYK %s loses to %s from %s (BrandCmykPalette logs this today; first wins)', implode('/', $values), implode('/', $palette[$hex]), $firstManual[$hex]),
                    ];
                }

                continue;
            }

            $palette[$hex] = $values;
            $firstManual[$hex] = $occurrence['manual'] >= 0 ? $manuals[$occurrence['manual']]['name'] : 'palette';
        }

        ksort($palette);

        return $palette;
    }

    /**
     * GetProjectAvatars: brand manuals first, then `created_at` (input order).
     *
     * @param list<ManualInput> $manuals
     * @return list<int>
     */
    private static function avatarOrder(array $manuals): array
    {
        $brand = [];
        $other = [];

        foreach ($manuals as $m => $manual) {
            if ($manual['brand']) {
                $brand[] = $m;
            } else {
                $other[] = $m;
            }
        }

        return [...$brand, ...$other];
    }

    /**
     * @param list<array{hex: string, role: null|string, ...}> $colors
     * @return list<string>
     */
    private static function keysWithRole(array $colors, string $role): array
    {
        $keys = [];

        foreach ($colors as $color) {
            if ($color['role'] === $role) {
                $keys[] = RichText::normalizeHexColor($color['hex']) ?? $color['hex'];
            }
        }

        return $keys;
    }

    /**
     * @param list<string> $hexes
     * @return list<string>
     */
    private static function normalizedUnique(array $hexes): array
    {
        $colors = [];

        foreach ($hexes as $hex) {
            $normalized = RichText::normalizeHexColor($hex);

            if ($normalized !== null && !in_array($normalized, $colors, true)) {
                $colors[] = $normalized;
            }
        }

        return $colors;
    }

    private static function rank(null|string $role): int
    {
        return match ($role) {
            self::ROLE_PRIMARY => 0,
            self::ROLE_SECONDARY => 1,
            default => 2,
        };
    }

    private static function roleOf(int $rank): null|string
    {
        return match ($rank) {
            0 => self::ROLE_PRIMARY,
            1 => self::ROLE_SECONDARY,
            default => null,
        };
    }

    private static function isWhite(string $hex): bool
    {
        $hex = strtolower($hex);

        return $hex === 'fff' || $hex === 'ffffff';
    }

    private static function textKey(null|string $value): string
    {
        $value = $value === null ? '' : trim($value);

        return $value;
    }

    /**
     * @param null|Cmyk $cmyk
     */
    private static function describePrint(null|array $cmyk, null|string $pantone, null|string $ral): string
    {
        return sprintf(
            'cmyk=%s pantone=%s ral=%s',
            $cmyk === null ? '–' : implode('/', array_map(static fn (null|string $c): string => $c ?? '∅', $cmyk)),
            $pantone ?? '–',
            $ral ?? '–',
        );
    }

    /**
     * @param list<string> $values
     */
    private static function listOrDash(array $values): string
    {
        return $values === [] ? '–' : implode(',', $values);
    }

    /**
     * @param ManualInput $manual
     * @return array{type: string, manualId: string, manual: string, hex: string, detail: string}
     */
    private static function finding(string $type, array $manual, string $hex, string $detail): array
    {
        return ['type' => $type, 'manualId' => $manual['id'], 'manual' => $manual['name'], 'hex' => $hex, 'detail' => $detail];
    }

    /**
     * @return array<mixed>
     */
    private static function decode(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @return list<RawColor>
     */
    private static function rawColors(mixed $value): array
    {
        $colors = [];

        foreach (self::decode($value) as $color) {
            if (!is_array($color)) {
                continue;
            }

            $cmyk = null;
            if (is_array($color['cmyk'] ?? null)) {
                $channels = array_values($color['cmyk']);
                $channel = static fn (int $i): null|string => is_scalar($channels[$i] ?? null) ? (string) $channels[$i] : null;
                $cmyk = [$channel(0), $channel(1), $channel(2), $channel(3)];
            }

            $colors[] = [
                'color' => is_scalar($color['color'] ?? null) ? (string) $color['color'] : '',
                'type' => is_string($color['type'] ?? null) ? $color['type'] : null,
                'pantone' => is_scalar($color['pantone'] ?? null) ? (string) $color['pantone'] : null,
                'ral' => is_scalar($color['ral'] ?? null) ? (string) $color['ral'] : null,
                'cmyk' => $cmyk,
            ];
        }

        return $colors;
    }
}
