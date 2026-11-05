<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Palette;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Services\ProvideIdentity;

/**
 * Re-projects ONE manual's JSONB colours into the project palette
 * (`project_color`) and the manual's links (`manual_palette_color`) — the
 * release-R1 write-path sync of `docs/redesign/palette-plan.md` (P1a).
 *
 * Since R2 (P1b) the app writes through {@see ManualPaletteWriter}; this
 * projector is only `app:palette:reconcile`'s "the JSONB is newer" repair (R1
 * code wrote it during a blue-green overlap / rollback) — exactly what the R1
 * handlers ran, so a manual R1 wrote ends up with the links R1 would give it.
 * Rules = the backfill's own ({@see PaletteBackfillPlanner}):
 *  - the manual is split exactly like the backfill split it (rebuilt logo
 *    segment, custom segment, typed stale detected colours);
 *  - every colour reuses a palette colour of the same hex key — first one
 *    already carrying its print data, else first compatible one, whose empty
 *    fields it fills (§7) — or creates one at the END of the palette;
 *  - role (D2, per project): a new palette colour takes the role this manual
 *    gives it; an existing one changes only when THIS write changed the
 *    manual's role for it (the old colours page posts every type on every
 *    save — "last write wins" must not fire on an untouched row), and never
 *    from a logo upload (`$rolesBefore` null);
 *  - the manual's links are rewritten (logo segment, then custom, one
 *    position sequence);
 *  - palette colours are NEVER deleted in R1 (an unused one is harmless —
 *    `app:palette:verify` lists it).
 *
 * Plain DBAL on purpose: the link rewrite is DELETE-then-INSERT, and the ORM
 * unit of work runs inserts before deletes, which would trip
 * UNIQUE (manual_id, logo_hex). It reads the STORED columns, so the caller
 * flushes the manual first. The project row is locked FOR UPDATE so two
 * writes in one project cannot both create the same palette colour.
 *
 * @phpstan-import-type PrintData from PaletteBackfillPlanner
 */
readonly final class ProjectManualColors
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private ProvideIdentity $provideIdentity,
    ) {
    }

    /**
     * The role the manual gives each colour in its STORED columns (hex key →
     * primary|secondary|null) — take it before a colours write and hand it to
     * {@see reproject()}.
     *
     * @return array<string, null|string>
     */
    public function rolesOf(UuidInterface $manualId): array
    {
        $row = $this->manualRow($manualId);

        if ($row === null) {
            return [];
        }

        return PaletteBackfillPlanner::manualRoles(PaletteBackfillPlanner::segments(0, self::input($row)));
    }

    /**
     * @param null|array<string, null|string> $rolesBefore {@see rolesOf()} before the write; null = this write
     *   does not set roles (logo upload, reconcile)
     */
    public function reproject(UuidInterface $manualId, null|array $rolesBefore = null): void
    {
        $row = $this->manualRow($manualId);

        if ($row === null) {
            return;
        }

        $projectId = $row['project_id'];
        $this->connection->executeStatement('SELECT id FROM project WHERE id = ? FOR UPDATE', [$projectId]);

        $segments = PaletteBackfillPlanner::segments(0, self::input($row));
        $rolesNow = PaletteBackfillPlanner::manualRoles($segments);
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        /** @var list<array{id: string, hex_key: string, role: null|string, cmyk: null|string, pantone: null|string, ral: null|string, position: int|string}> $paletteRows */
        $paletteRows = $this->connection->fetchAllAssociative(
            'SELECT id, hex_key, role, cmyk, pantone, ral, position FROM project_color WHERE project_id = ? ORDER BY position, id',
            [$projectId],
        );

        /** @var array<string, array<int, PrintData>> $printsByGroup hex key → palette index → print data */
        $printsByGroup = [];
        /** @var list<string> $paletteIds */
        $paletteIds = [];
        /** @var list<null|string> $paletteRoles */
        $paletteRoles = [];
        $nextPosition = 0;

        foreach ($paletteRows as $paletteRow) {
            $index = count($paletteIds);
            $paletteIds[] = $paletteRow['id'];
            $paletteRoles[] = $paletteRow['role'];
            $printsByGroup[$paletteRow['hex_key']][$index] = self::printOfRow($paletteRow);
            $nextPosition = max($nextPosition, (int) $paletteRow['position'] + 1);
        }

        /** @var array<string, int> $paletteIndexByOccurrence */
        $paletteIndexByOccurrence = [];
        /** @var array<int, true> $dirtyPrint */
        $dirtyPrint = [];
        /** @var array<int, string> $groupByIndex */
        $groupByIndex = [];

        foreach (PaletteBackfillPlanner::manualWalk($segments) as $occurrence) {
            $groupKey = PaletteBackfillPlanner::groupKey($occurrence);
            $print = PaletteBackfillPlanner::printOf($occurrence);
            $index = PaletteBackfillPlanner::matchPrint($print, $printsByGroup[$groupKey] ?? []);

            if ($index === null) {
                $index = count($paletteIds);
                $id = $this->provideIdentity->next()->toString();
                $role = $rolesNow[$groupKey] ?? null;
                $paletteIds[] = $id;
                $paletteRoles[] = $role;
                $printsByGroup[$groupKey][$index] = $print;

                $this->connection->executeStatement(
                    'INSERT INTO project_color (id, project_id, hex, hex_key, role, cmyk, pantone, ral, position, created_at) VALUES (?, ?, ?, ?, ?, CAST(? AS JSONB), ?, ?, ?, ?)',
                    [$id, $projectId, $occurrence['hex'], $groupKey, $role, self::encodeCmyk($print['cmyk']), $print['pantone'], $print['ral'], $nextPosition++, $now],
                );
            } elseif (PaletteBackfillPlanner::gainedPrint($printsByGroup[$groupKey][$index], $print) !== []) {
                $printsByGroup[$groupKey][$index] = PaletteBackfillPlanner::unionPrint($printsByGroup[$groupKey][$index], $print);
                $dirtyPrint[$index] = true;
            }

            $groupByIndex[$index] = $groupKey;
            $paletteIndexByOccurrence[PaletteBackfillPlanner::occurrenceId($occurrence)] = $index;
        }

        foreach (array_keys($dirtyPrint) as $index) {
            $print = $printsByGroup[$groupByIndex[$index]][$index];

            $this->connection->executeStatement(
                'UPDATE project_color SET cmyk = CAST(? AS JSONB), pantone = ?, ral = ? WHERE id = ?',
                [self::encodeCmyk($print['cmyk']), $print['pantone'], $print['ral'], $paletteIds[$index]],
            );
        }

        if ($rolesBefore !== null) {
            foreach ($groupByIndex as $index => $groupKey) {
                $roleNow = $rolesNow[$groupKey] ?? null;
                $changed = array_key_exists($groupKey, $rolesBefore)
                    ? $rolesBefore[$groupKey] !== $roleNow
                    : $roleNow !== null;

                if ($changed && $paletteRoles[$index] !== $roleNow) {
                    $this->connection->executeStatement('UPDATE project_color SET role = ? WHERE id = ?', [$roleNow, $paletteIds[$index]]);
                    $paletteRoles[$index] = $roleNow;
                }
            }
        }

        $this->connection->executeStatement('DELETE FROM manual_palette_color WHERE manual_id = ?', [$row['id']]);

        $position = 0;
        $seenLogoHex = [];

        foreach ([...$segments['logo'], ...$segments['custom']] as $occurrence) {
            $logoHex = null;

            if ($occurrence['source'] !== 'custom' && !isset($seenLogoHex[$occurrence['hex']])) {
                $logoHex = $occurrence['hex'];
                $seenLogoHex[$logoHex] = true;
            }

            $this->connection->executeStatement(
                'INSERT INTO manual_palette_color (id, manual_id, project_color_id, logo_hex, position, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $this->provideIdentity->next()->toString(),
                    $row['id'],
                    $paletteIds[$paletteIndexByOccurrence[PaletteBackfillPlanner::occurrenceId($occurrence)]],
                    $logoHex,
                    $position++,
                    $now,
                ],
            );
        }
    }

    /**
     * @return null|array{id: string, project_id: string, name: string, type: string, logo: null|string, detected_colors: null|string, custom_colors: null|string}
     */
    private function manualRow(UuidInterface $manualId): null|array
    {
        /** @var false|array{id: string, project_id: string, name: string, type: string, logo: null|string, detected_colors: null|string, custom_colors: null|string} $row */
        $row = $this->connection->fetchAssociative(
            'SELECT id, project_id, name, type, logo, detected_colors, custom_colors FROM manual WHERE id = ?',
            [$manualId->toString()],
        );

        return $row === false ? null : $row;
    }

    /**
     * @param array{id: string, project_id: string, name: string, type: string, logo: null|string, detected_colors: null|string, custom_colors: null|string} $row
     * @return array{id: string, name: string, brand: bool, logoColors: list<string>, detected: list<array{color: string, type: null|string, pantone: null|string, ral: null|string, cmyk: null|array{null|string, null|string, null|string, null|string}}>, custom: list<array{color: string, type: null|string, pantone: null|string, ral: null|string, cmyk: null|array{null|string, null|string, null|string, null|string}}>}
     */
    private static function input(array $row): array
    {
        return PaletteBackfillPlanner::manualFromStorage($row['id'], $row['name'], $row['type'] === 'brand', $row['logo'], $row['detected_colors'], $row['custom_colors']);
    }

    /**
     * @param array{cmyk: null|string, pantone: null|string, ral: null|string, ...} $row
     * @return PrintData
     */
    private static function printOfRow(array $row): array
    {
        $cmyk = null;

        if ($row['cmyk'] !== null) {
            $decoded = json_decode($row['cmyk'], true);

            if (is_array($decoded)) {
                $channels = array_values($decoded);
                $channel = static fn (int $i): null|string => is_scalar($channels[$i] ?? null) ? (string) $channels[$i] : null;
                $cmyk = [$channel(0), $channel(1), $channel(2), $channel(3)];
            }
        }

        return PaletteBackfillPlanner::printOf(['cmyk' => $cmyk, 'pantone' => $row['pantone'], 'ral' => $row['ral']]);
    }

    /**
     * @param null|array{null|string, null|string, null|string, null|string} $cmyk
     */
    private static function encodeCmyk(null|array $cmyk): null|string
    {
        return $cmyk === null ? null : json_encode($cmyk, JSON_THROW_ON_ERROR);
    }
}
