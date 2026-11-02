<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use WBoost\Web\Entity\Project;

/**
 * One batched query for the projects dashboard cards (no per-card N+1):
 * manual count, "has a palette" and font count per project.
 */
readonly final class GetProjectCardFacts
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<Project> $projects
     * @return array<string, ProjectCardRow> keyed by project id
     */
    public function forProjects(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        $ids = array_values(array_map(
            static fn (Project $project): string => $project->id->toString(),
            $projects,
        ));

        /** @var list<array{id: string, manuals: int|string, has_palette: bool|string, fonts: int|string}> $rows */
        $rows = $this->entityManager->getConnection()->executeQuery(
            <<<'SQL'
                SELECT p.id,
                       (SELECT COUNT(*) FROM manual m WHERE m.project_id = p.id) AS manuals,
                       EXISTS (
                           SELECT 1 FROM manual m
                           WHERE m.project_id = p.id
                             AND (m.custom_colors <> '[]'::jsonb
                                  OR jsonb_path_exists(m.logo, '$.*.detectedColors[0]'))
                       ) AS has_palette,
                       (SELECT COUNT(*) FROM font f WHERE f.project_id = p.id) AS fonts
                FROM project p
                WHERE p.id IN (:ids)
                SQL,
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $facts = [];

        foreach ($rows as $row) {
            $facts[$row['id']] = new ProjectCardRow(
                manualsCount: (int) $row['manuals'],
                hasPalette: $row['has_palette'] === true || $row['has_palette'] === 't' || $row['has_palette'] === '1',
                fontsCount: (int) $row['fonts'],
            );
        }

        return $facts;
    }
}
