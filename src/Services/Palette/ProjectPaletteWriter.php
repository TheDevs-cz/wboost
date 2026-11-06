<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Palette;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Exceptions\ProjectColorHexLocked;
use WBoost\Web\Exceptions\ProjectColorInLogo;
use WBoost\Web\Exceptions\ProjectColorNotFound;
use WBoost\Web\Exceptions\ProjectColorUsedInManuals;
use WBoost\Web\Exceptions\ProjectColorsNotDuplicates;
use WBoost\Web\Value\ManualColorType;
use WBoost\Web\Value\ProjectColorField;

/**
 * Write path of the "Paleta barev" page (F7 P2): edits ONE palette colour
 * (`project_color`) for every manual that links it — that is option C, the
 * palette is the source of truth — adds colours (unlinked until a manual
 * picks them, P3), reorders the palette, deletes colours behind the
 * guard (a logo colour never; one manuals added only after the explicit
 * "remove from manuals" confirmation) and merges same-HEX duplicates
 * ("Sloučit", P4 — {@see mergeColors()}).
 *
 * Same contract as {@see ManualPaletteWriter}: DBAL, the project row locked
 * FOR UPDATE, one transaction, then the managed entities are brought in step
 * ({@see ManualPaletteWriter::refreshManaged()}). Every linking manual reads
 * the edit through its links — there is no per-manual copy to rewrite (the
 * R2 JSONB mirror is gone since P5a).
 *
 * Validation of the posted values is the caller's ({@see PaletteColorInput});
 * this class only enforces the invariants: the HEX of a logo-linked colour is
 * the logo's, and the delete guard.
 */
readonly final class ProjectPaletteWriter
{
    public function __construct(
        private Connection $connection,
        private ManualPaletteWriter $manualPaletteWriter,
        private ClockInterface $clock,
    ) {
    }

    /**
     * A new palette colour at the END of the palette, no role, no manual.
     */
    public function addColor(UuidInterface $colorId, UuidInterface $projectId, string $hex): void
    {
        $this->connection->transactional(function () use ($colorId, $projectId, $hex): void {
            $this->lockProject($projectId->toString());

            $position = $this->connection->fetchOne('SELECT COALESCE(MAX(position) + 1, 0) FROM project_color WHERE project_id = ?', [$projectId->toString()]);

            $this->connection->executeStatement(
                'INSERT INTO project_color (id, project_id, hex, hex_key, role, cmyk, pantone, ral, name, position, created_at) VALUES (?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL, ?, ?)',
                [$colorId->toString(), $projectId->toString(), $hex, PaletteColorInput::hexKey($hex), (int) (is_numeric($position) ? $position : 0), $this->clock->now()->format('Y-m-d H:i:s')],
            );
        });

        $this->manualPaletteWriter->refreshManaged($projectId->toString());
    }

    /**
     * One field of one colour. `$value` carries hex / name / role / pantone /
     * ral (role: `primary`, `secondary` or null = "Bez role"), `$cmyk` the
     * CMYK tuple — both already normalised by {@see PaletteColorInput}.
     *
     * @param null|array{null|string, null|string, null|string, null|string} $cmyk
     * @throws ProjectColorNotFound
     * @throws ProjectColorHexLocked
     */
    public function editColor(UuidInterface $colorId, ProjectColorField $field, null|string $value, null|array $cmyk = null): void
    {
        $projectId = $this->connection->transactional(function () use ($colorId, $field, $value, $cmyk): string {
            $color = $this->lockedColor($colorId);
            $id = $colorId->toString();
            switch ($field) {
                case ProjectColorField::Hex:
                    if ($value === null) {
                        throw new \InvalidArgumentException('A palette colour needs a HEX.');
                    }

                    if ($value !== $color['hex']) {
                        if ($this->logoLinks($id) > 0) {
                            throw new ProjectColorHexLocked();
                        }

                        $this->connection->executeStatement('UPDATE project_color SET hex = ?, hex_key = ? WHERE id = ?', [$value, PaletteColorInput::hexKey($value), $id]);
                    }
                    break;

                case ProjectColorField::Role:
                    $role = $value === null ? null : ManualColorType::from($value)->value;

                    if ($role !== $color['role']) {
                        $this->connection->executeStatement('UPDATE project_color SET role = ? WHERE id = ?', [$role, $id]);
                    }
                    break;

                case ProjectColorField::Cmyk:
                    $encoded = $cmyk === null ? null : json_encode($cmyk, JSON_THROW_ON_ERROR);
                    $current = $color['cmyk'] === null ? null : json_encode(json_decode($color['cmyk'], true), JSON_THROW_ON_ERROR);

                    if ($encoded !== $current) {
                        $this->connection->executeStatement('UPDATE project_color SET cmyk = CAST(? AS JSONB) WHERE id = ?', [$encoded, $id]);
                    }
                    break;

                case ProjectColorField::Name:
                case ProjectColorField::Pantone:
                case ProjectColorField::Ral:
                    $column = $field->value;

                    if ($value !== $color[$column]) {
                        $this->connection->executeStatement(sprintf('UPDATE project_color SET %s = ? WHERE id = ?', $column), [$value, $id]);
                    }
                    break;
            }

            return $color['project_id'];
        });

        $this->manualPaletteWriter->refreshManaged($projectId);
    }

    /**
     * Reorders the given colours of ONE project among the positions they
     * already hold — the page drags inside a group ("Z logotypu" / "Přidané
     * barvy"), so the other group's colours keep their places in the palette
     * order (D5: what templates, print and the API list). Unknown ids and
     * colours of another project are ignored.
     *
     * @param array<string> $colorIds the new order
     */
    public function sortColors(UuidInterface $projectId, array $colorIds): void
    {
        $this->connection->transactional(function () use ($projectId, $colorIds): void {
            $this->lockProject($projectId->toString());

            /** @var array<string, int|string> $positions id → position */
            $positions = $this->connection->fetchAllKeyValue('SELECT id, position FROM project_color WHERE project_id = ?', [$projectId->toString()]);

            $ids = [];
            foreach ($colorIds as $colorId) {
                if (Uuid::isValid($colorId) && isset($positions[strtolower($colorId)]) && !in_array(strtolower($colorId), $ids, true)) {
                    $ids[] = strtolower($colorId);
                }
            }

            $slots = array_map(static fn (string $id): int => (int) $positions[$id], $ids);
            sort($slots);

            foreach ($ids as $index => $id) {
                if ((int) $positions[$id] !== $slots[$index]) {
                    $this->connection->executeStatement('UPDATE project_color SET position = ? WHERE id = ?', [$slots[$index], $id]);
                }
            }
        });

        $this->manualPaletteWriter->refreshManaged($projectId->toString());
    }

    /**
     * Deletes a palette colour. Guard: a colour any manual's LOGO uses is
     * never deleted (it goes away with a new logo — D3); one manuals added by
     * hand only with `$removeFromManuals` (their links go with it, the later
     * colours of those manuals move up one place); an unused one always.
     *
     * @throws ProjectColorNotFound
     * @throws ProjectColorInLogo
     * @throws ProjectColorUsedInManuals
     */
    public function deleteColor(UuidInterface $colorId, bool $removeFromManuals): void
    {
        $projectId = $this->connection->transactional(function () use ($colorId, $removeFromManuals): string {
            $color = $this->lockedColor($colorId);
            $id = $colorId->toString();

            if ($this->logoLinks($id) > 0) {
                throw new ProjectColorInLogo();
            }

            $links = self::count($this->connection->fetchOne('SELECT COUNT(*) FROM manual_palette_color WHERE project_color_id = ?', [$id]));

            if ($links > 0 && !$removeFromManuals) {
                throw new ProjectColorUsedInManuals();
            }

            $this->connection->executeStatement('DELETE FROM manual_palette_color WHERE project_color_id = ?', [$id]);
            $this->connection->executeStatement('DELETE FROM project_color WHERE id = ?', [$id]);

            return $color['project_id'];
        });

        $this->manualPaletteWriter->refreshManaged($projectId);
    }

    /**
     * "Sloučit" (F7 P4): merges same-HEX palette colours into `$survivorId`.
     *
     *  1. Fill the gap ({@see PaletteColorMerge::mergedFields()}): the
     *     survivor's empty CMYK / Pantone / RAL / Název take the losers'
     *     values (palette order); a filled survivor field is never
     *     overwritten; role and HEX spelling stay the survivor's.
     *  2. The survivor takes the EARLIEST palette position of the group, so
     *     the swatch order templates / print / API see keeps the HEX where
     *     it was (computeColors already deduped by HEX at its first place).
     *  3. Every link of a loser moves to the survivor (a logo link keeps its
     *     literal `logo_hex`). A manual that ends up linking it more than once
     *     keeps its logo links (each literal is a recolouring key) and drops
     *     the added ones; with no logo link it keeps ONE added link, at the
     *     earliest of their positions.
     *  4. The losers are deleted.
     *
     * @param list<UuidInterface> $loserIds
     * @throws ProjectColorNotFound
     * @throws ProjectColorsNotDuplicates
     */
    public function mergeColors(UuidInterface $survivorId, array $loserIds): void
    {
        $projectId = $this->connection->transactional(function () use ($survivorId, $loserIds): string {
            $survivor = $this->lockedColor($survivorId);

            /** @var array<string, true> $ids */
            $ids = [];
            foreach ($loserIds as $loserId) {
                $ids[$loserId->toString()] = true;
            }
            unset($ids[$survivor['id']]);

            if ($ids === []) {
                throw new ProjectColorsNotDuplicates();
            }

            /** @var list<array{id: string, project_id: string, hex_key: string, cmyk: null|string, name: null|string, pantone: null|string, ral: null|string, position: int|string}> $losers */
            $losers = $this->connection->fetchAllAssociative(
                'SELECT id, project_id, hex_key, cmyk, name, pantone, ral, position FROM project_color WHERE id IN (?) ORDER BY position, id',
                [array_keys($ids)],
                [ArrayParameterType::STRING],
            );

            if (count($losers) !== count($ids)) {
                throw new ProjectColorNotFound();
            }

            $positions = [(int) $survivor['position']];

            foreach ($losers as $loser) {
                if ($loser['project_id'] !== $survivor['project_id'] || $loser['hex_key'] !== $survivor['hex_key']) {
                    throw new ProjectColorsNotDuplicates();
                }

                $positions[] = (int) $loser['position'];
            }

            // 1. + 2.
            $merged = PaletteColorMerge::mergedFields(self::mergeFieldsOfRow($survivor), array_map(self::mergeFieldsOfRow(...), $losers));
            $this->connection->executeStatement(
                'UPDATE project_color SET cmyk = CAST(? AS JSONB), pantone = ?, ral = ?, name = ?, position = ? WHERE id = ?',
                [$merged['cmyk'] === null ? null : json_encode($merged['cmyk'], JSON_THROW_ON_ERROR), $merged['pantone'], $merged['ral'], $merged['name'], min($positions), $survivor['id']],
            );

            // 3.
            $loserIdList = array_map(static fn (array $loser): string => $loser['id'], $losers);
            /** @var list<array{id: string, manual_id: string, project_color_id: string, logo_hex: null|string, position: int|string}> $links */
            $links = $this->connection->fetchAllAssociative(
                'SELECT id, manual_id, project_color_id, logo_hex, position FROM manual_palette_color WHERE project_color_id IN (?) ORDER BY manual_id, position, id',
                [[$survivor['id'], ...$loserIdList]],
                [ArrayParameterType::STRING],
            );

            /** @var array<string, list<array{id: string, manual_id: string, project_color_id: string, logo_hex: null|string, position: int|string}>> $byManual */
            $byManual = [];
            foreach ($links as $link) {
                $byManual[$link['manual_id']][] = $link;
            }

            foreach ($byManual as $manualLinks) {
                $logoLinks = array_values(array_filter($manualLinks, static fn (array $link): bool => $link['logo_hex'] !== null));
                $addedLinks = array_values(array_filter($manualLinks, static fn (array $link): bool => $link['logo_hex'] === null));

                if ($logoLinks !== []) {
                    $keep = $logoLinks;
                    $drop = $addedLinks;
                    $keptPosition = null;
                } elseif ($addedLinks !== []) {
                    // The survivor's own added link stays (else the first), at the earliest place.
                    $kept = array_values(array_filter($addedLinks, static fn (array $link): bool => $link['project_color_id'] === $survivor['id']))[0] ?? $addedLinks[0];
                    $keep = [$kept];
                    $drop = array_values(array_filter($addedLinks, static fn (array $link): bool => $link['id'] !== $kept['id']));
                    $keptPosition = min(array_map(static fn (array $link): int => (int) $link['position'], $addedLinks));
                } else {
                    continue;
                }

                foreach ($drop as $link) {
                    $this->connection->executeStatement('DELETE FROM manual_palette_color WHERE id = ?', [$link['id']]);
                }

                foreach ($keep as $link) {
                    $position = $keptPosition ?? (int) $link['position'];

                    if ($link['project_color_id'] !== $survivor['id'] || $position !== (int) $link['position']) {
                        $this->connection->executeStatement(
                            'UPDATE manual_palette_color SET project_color_id = ?, position = ? WHERE id = ?',
                            [$survivor['id'], $position, $link['id']],
                        );
                    }
                }
            }

            // 4.
            $this->connection->executeStatement('DELETE FROM project_color WHERE id IN (?)', [$loserIdList], [ArrayParameterType::STRING]);

            return $survivor['project_id'];
        });

        $this->manualPaletteWriter->refreshManaged($projectId);
    }

    /**
     * @param array{cmyk: null|string, name: null|string, pantone: null|string, ral: null|string, ...} $row
     * @return array{cmyk: null|array{null|string, null|string, null|string, null|string}, pantone: null|string, ral: null|string, name: null|string}
     */
    private static function mergeFieldsOfRow(array $row): array
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

        return ['cmyk' => $cmyk, 'pantone' => $row['pantone'], 'ral' => $row['ral'], 'name' => $row['name']];
    }

    /**
     * @return array{id: string, project_id: string, hex: string, hex_key: string, role: null|string, cmyk: null|string, name: null|string, pantone: null|string, ral: null|string, position: int|string}
     * @throws ProjectColorNotFound
     */
    private function lockedColor(UuidInterface $colorId): array
    {
        $projectId = $this->connection->fetchOne('SELECT project_id FROM project_color WHERE id = ?', [$colorId->toString()]);

        if (!is_string($projectId)) {
            throw new ProjectColorNotFound();
        }

        $this->lockProject($projectId);

        /** @var false|array{id: string, project_id: string, hex: string, hex_key: string, role: null|string, cmyk: null|string, name: null|string, pantone: null|string, ral: null|string, position: int|string} $color */
        $color = $this->connection->fetchAssociative('SELECT id, project_id, hex, hex_key, role, cmyk, name, pantone, ral, position FROM project_color WHERE id = ?', [$colorId->toString()]);

        if ($color === false) {
            throw new ProjectColorNotFound();
        }

        return $color;
    }

    private function lockProject(string $projectId): void
    {
        $this->connection->executeStatement('SELECT id FROM project WHERE id = ? FOR UPDATE', [$projectId]);
    }

    private function logoLinks(string $colorId): int
    {
        return self::count($this->connection->fetchOne('SELECT COUNT(*) FROM manual_palette_color WHERE project_color_id = ? AND logo_hex IS NOT NULL', [$colorId]));
    }

    private static function count(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
