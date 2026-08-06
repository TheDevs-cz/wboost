<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\DimensionPreset;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\TemplateDimension;

/**
 * Per-project template aggregates: how many templates and variants a project
 * holds, and which DISTINCT dimensions those variants are drawn at.
 *
 * Pure read model — raw SQL aggregation, no entity hydration, and deliberately
 * batched over a LIST of projects: the caller ({@see \WBoost\Web\Mcp\Tool\GetContextTool})
 * summarises every project a user can see at once, and an admin can see all of
 * them. Walking `Template::$variants` instead would issue a query per template
 * (the association is `fetch: 'EAGER'` but not fetch-joined) to compute numbers
 * the database can group in one pass.
 */
readonly final class GetProjectTemplateStats
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<UuidInterface> $projectIds
     *
     * @return array<string, ProjectTemplateStats> keyed by project id; every
     *         requested project is present, empty ones as {@see ProjectTemplateStats::none()}
     */
    public function forProjects(array $projectIds): array
    {
        /** @var array<string, ProjectTemplateStats> $stats */
        $stats = [];

        foreach ($projectIds as $projectId) {
            $stats[$projectId->toString()] = ProjectTemplateStats::none();
        }

        if ($stats === []) {
            return $stats;
        }

        $ids = array_keys($stats);
        $dimensions = $this->dimensionsByProject($ids);

        // LEFT JOIN so a template with no variants still counts as a template;
        // COUNT(v.id) then correctly counts 0 for it (COUNT over a column
        // ignores NULLs, unlike COUNT(*)).
        $sql = <<<SQL
            SELECT
              t.project_id AS project_id,
              COUNT(DISTINCT t.id) AS template_count,
              COUNT(v.id) AS variant_count
            FROM template t
            LEFT JOIN template_variant v ON v.template_id = t.id
            WHERE t.project_id IN (:projectIds)
            GROUP BY t.project_id
        SQL;

        /** @var list<array{project_id: string, template_count: int|string, variant_count: int|string}> $rows */
        $rows = $this->entityManager->getConnection()
            ->executeQuery($sql, ['projectIds' => $ids], ['projectIds' => ArrayParameterType::STRING])
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $stats[$row['project_id']] = new ProjectTemplateStats(
                templateCount: (int) $row['template_count'],
                variantCount: (int) $row['variant_count'],
                dimensions: $dimensions[$row['project_id']] ?? [],
            );
        }

        return $stats;
    }

    /**
     * @param list<string> $projectIds
     *
     * @return array<string, list<TemplateDimensionUsage>>
     */
    private function dimensionsByProject(array $projectIds): array
    {
        // The GROUP BY key is the whole embeddable, so one row per distinct
        // dimension. Ordering is fully determined by that key (most-used first,
        // then the size) — the result feeds a cached payload, and a payload
        // whose element order wobbles between identical inputs is not one a
        // test can assert on.
        $sql = <<<SQL
            SELECT
              t.project_id AS project_id,
              v.dimension_unit AS unit,
              v.dimension_unit_width AS unit_width,
              v.dimension_unit_height AS unit_height,
              v.dimension_preset AS preset,
              COUNT(*) AS variant_count
            FROM template_variant v
            INNER JOIN template t ON t.id = v.template_id
            WHERE t.project_id IN (:projectIds)
            GROUP BY t.project_id, v.dimension_unit, v.dimension_unit_width, v.dimension_unit_height, v.dimension_preset
            ORDER BY t.project_id, COUNT(*) DESC, v.dimension_unit, v.dimension_unit_width, v.dimension_unit_height, v.dimension_preset
        SQL;

        /** @var list<array{project_id: string, unit: string, unit_width: float|string, unit_height: float|string, preset: null|string, variant_count: int|string}> $rows */
        $rows = $this->entityManager->getConnection()
            ->executeQuery($sql, ['projectIds' => $projectIds], ['projectIds' => ArrayParameterType::STRING])
            ->fetchAllAssociative();

        /** @var array<string, list<TemplateDimensionUsage>> $byProject */
        $byProject = [];

        foreach ($rows as $row) {
            $preset = $row['preset'];

            $byProject[$row['project_id']][] = new TemplateDimensionUsage(
                dimension: new TemplateDimension(
                    unit: DimensionUnit::from($row['unit']),
                    unitWidth: (float) $row['unit_width'],
                    unitHeight: (float) $row['unit_height'],
                    preset: $preset === null ? null : DimensionPreset::from($preset),
                ),
                variantCount: (int) $row['variant_count'],
            );
        }

        return $byProject;
    }
}
