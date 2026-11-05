<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Palette;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Exceptions\ProjectColorHexLocked;
use WBoost\Web\Exceptions\ProjectColorInLogo;
use WBoost\Web\Exceptions\ProjectColorNotFound;
use WBoost\Web\Exceptions\ProjectColorUsedInManuals;
use WBoost\Web\Value\ManualColorType;
use WBoost\Web\Value\ProjectColorField;

/**
 * Write path of the "Paleta barev" page (F7 P2): edits ONE palette colour
 * (`project_color`) for every manual that links it — that is option C, the
 * palette is the source of truth — adds colours (unlinked until a manual
 * picks them, P3), reorders the palette and deletes colours behind the
 * guard (a logo colour never; one manuals added only after the explicit
 * "remove from manuals" confirmation).
 *
 * Same contract as {@see ManualPaletteWriter}: DBAL, the project row locked
 * FOR UPDATE, one transaction, then the R2 JSONB mirror of the project is
 * rewritten ({@see ManualPaletteWriter::mirrorProject()} — a role, HEX or
 * print edit has to reach the `detected_colors` / `custom_colors` of every
 * linking manual, R1 code reads those) and the managed entities are brought
 * in step ({@see ManualPaletteWriter::refreshManaged()}).
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
            $changed = false;

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
                        $changed = true;
                    }
                    break;

                case ProjectColorField::Role:
                    $role = $value === null ? null : ManualColorType::from($value)->value;

                    if ($role !== $color['role']) {
                        $this->connection->executeStatement('UPDATE project_color SET role = ? WHERE id = ?', [$role, $id]);
                        $changed = true;
                    }
                    break;

                case ProjectColorField::Cmyk:
                    $encoded = $cmyk === null ? null : json_encode($cmyk, JSON_THROW_ON_ERROR);
                    $current = $color['cmyk'] === null ? null : json_encode(json_decode($color['cmyk'], true), JSON_THROW_ON_ERROR);

                    if ($encoded !== $current) {
                        $this->connection->executeStatement('UPDATE project_color SET cmyk = CAST(? AS JSONB) WHERE id = ?', [$encoded, $id]);
                        $changed = true;
                    }
                    break;

                case ProjectColorField::Name:
                case ProjectColorField::Pantone:
                case ProjectColorField::Ral:
                    $column = $field->value;

                    if ($value !== $color[$column]) {
                        $this->connection->executeStatement(sprintf('UPDATE project_color SET %s = ? WHERE id = ?', $column), [$value, $id]);
                        $changed = true;
                    }
                    break;
            }

            // The name is not part of the manual read model or its mirror.
            if ($changed && $field !== ProjectColorField::Name) {
                $this->manualPaletteWriter->mirrorProject($color['project_id']);
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

            if ($links > 0) {
                $this->manualPaletteWriter->mirrorProject($color['project_id']);
            }

            return $color['project_id'];
        });

        $this->manualPaletteWriter->refreshManaged($projectId);
    }

    /**
     * @return array{id: string, project_id: string, hex: string, role: null|string, cmyk: null|string, name: null|string, pantone: null|string, ral: null|string}
     * @throws ProjectColorNotFound
     */
    private function lockedColor(UuidInterface $colorId): array
    {
        $projectId = $this->connection->fetchOne('SELECT project_id FROM project_color WHERE id = ?', [$colorId->toString()]);

        if (!is_string($projectId)) {
            throw new ProjectColorNotFound();
        }

        $this->lockProject($projectId);

        /** @var false|array{id: string, project_id: string, hex: string, role: null|string, cmyk: null|string, name: null|string, pantone: null|string, ral: null|string} $color */
        $color = $this->connection->fetchAssociative('SELECT id, project_id, hex, role, cmyk, name, pantone, ral FROM project_color WHERE id = ?', [$colorId->toString()]);

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
