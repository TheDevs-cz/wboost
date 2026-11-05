<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Palette;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * The project-palette backfill as SQL (F7 P1a) — FROZEN with the migration
 * that runs it (`Version20261105170659`) and the planner it feeds on.
 * Lives in src/ (not in the migration) so the kernel tests replay exactly
 * what the migration runs, and PHPStan sees it.
 *
 * Deterministic and safe to replay:
 *  - ids are UUIDv5 of (project id, palette position) / (manual id, link
 *    position); the data is {@see PaletteBackfillPlanner::plan()} over the
 *    raw columns (manuals `created_at, id`);
 *  - ONE statement per project: the palette INSERT is guarded by
 *    `NOT EXISTS (palette of this project)` and RETURNs its ids, the link
 *    INSERT joins those — so a project that already has a palette is
 *    skipped as a whole and never gets links pointing at a deterministic id
 *    that would mean another colour; a manual that already has links is
 *    skipped too. Guards see the snapshot the statement starts from.
 */
final class PaletteBackfillSql
{
    /** Namespace of the deterministic backfill ids — never change it. */
    public const string ID_NAMESPACE = '6f3c1a52-8e0b-5d4f-9a77-0c2b9e5d4a10';

    /**
     * One statement per project with manual colours (palette rows, then the
     * links joined to the rows it inserted).
     *
     * @return list<array{paletteRows: int, linkRows: int, sql: string, params: list<null|int|string>}>
     */
    public static function statements(Connection $connection): array
    {
        /** @var list<string> $projectIds */
        $projectIds = $connection->fetchFirstColumn('SELECT id FROM project ORDER BY id');

        /** @var list<array{id: string, project_id: string, name: string, type: string, logo: null|string, detected_colors: null|string, custom_colors: null|string}> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT id, project_id, name, type, logo, detected_colors, custom_colors FROM manual ORDER BY created_at, id',
        );

        $manualsByProject = [];
        foreach ($rows as $row) {
            $manualsByProject[$row['project_id']][] = PaletteBackfillPlanner::manualFromStorage(
                $row['id'],
                $row['name'],
                $row['type'] === 'brand',
                $row['logo'],
                $row['detected_colors'],
                $row['custom_colors'],
            );
        }

        $statements = [];

        foreach ($projectIds as $projectId) {
            $plan = PaletteBackfillPlanner::plan($manualsByProject[$projectId] ?? []);

            if ($plan->palette === []) {
                continue;
            }

            $values = [];
            $params = [];

            foreach ($plan->palette as $color) {
                $values[] = '(CAST(? AS UUID), CAST(? AS UUID), CAST(? AS VARCHAR), CAST(? AS VARCHAR), CAST(? AS VARCHAR), CAST(? AS JSONB), CAST(? AS VARCHAR), CAST(? AS VARCHAR), CAST(? AS INT))';
                array_push(
                    $params,
                    self::paletteId($projectId, $color['position']),
                    $projectId,
                    $color['hex'],
                    $color['hexKey'] ?? ('#' . strtolower($color['hex'])),
                    $color['role'],
                    $color['cmyk'] === null ? null : json_encode($color['cmyk'], JSON_THROW_ON_ERROR),
                    $color['pantone'],
                    $color['ral'],
                    $color['position'],
                );
            }

            $paletteSql = 'INSERT INTO project_color (id, project_id, hex, hex_key, role, cmyk, pantone, ral, position, created_at) '
                . 'SELECT v.id, v.project_id, v.hex, v.hex_key, v.role, v.cmyk, v.pantone, v.ral, v.position, LOCALTIMESTAMP(0) '
                . 'FROM (VALUES ' . implode(', ', $values) . ') AS v (id, project_id, hex, hex_key, role, cmyk, pantone, ral, position) '
                . 'WHERE NOT EXISTS (SELECT 1 FROM project_color existing WHERE existing.project_id = v.project_id) '
                . 'RETURNING id';

            if ($plan->links === []) {
                $statements[] = ['paletteRows' => count($plan->palette), 'linkRows' => 0, 'sql' => $paletteSql, 'params' => $params];
                continue;
            }

            $linkValues = [];

            foreach ($plan->links as $link) {
                $linkValues[] = '(CAST(? AS UUID), CAST(? AS UUID), CAST(? AS UUID), CAST(? AS VARCHAR), CAST(? AS INT))';
                array_push(
                    $params,
                    self::linkId($link['manualId'], $link['position']),
                    $link['manualId'],
                    self::paletteId($projectId, $link['palettePosition']),
                    $link['logoHex'],
                    $link['position'],
                );
            }

            // One statement per project: links join the palette rows THIS
            // statement inserted, so a skipped project never gets links that
            // would point at a deterministic id meaning another colour.
            $statements[] = [
                'paletteRows' => count($plan->palette),
                'linkRows' => count($plan->links),
                'sql' => 'WITH palette AS (' . $paletteSql . ') '
                    . 'INSERT INTO manual_palette_color (id, manual_id, project_color_id, logo_hex, position, created_at) '
                    . 'SELECT v.id, v.manual_id, v.project_color_id, v.logo_hex, v.position, LOCALTIMESTAMP(0) '
                    . 'FROM (VALUES ' . implode(', ', $linkValues) . ') AS v (id, manual_id, project_color_id, logo_hex, position) '
                    . 'JOIN palette ON palette.id = v.project_color_id '
                    . 'WHERE NOT EXISTS (SELECT 1 FROM manual_palette_color existing WHERE existing.manual_id = v.manual_id)',
                'params' => $params,
            ];
        }

        return $statements;
    }

    private static function paletteId(string $projectId, int $position): string
    {
        return Uuid::uuid5(self::ID_NAMESPACE, 'project_color:' . $projectId . ':' . $position)->toString();
    }

    private static function linkId(string $manualId, int $position): string
    {
        return Uuid::uuid5(self::ID_NAMESPACE, 'manual_palette_color:' . $manualId . ':' . $position)->toString();
    }
}
