<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\Project;
use WBoost\Web\Value\ProjectAvatar;

readonly final class GetProjectAvatars
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Builds an avatar for every given project: the logo of its first manual
     * that has one (brand manuals preferred), otherwise a monogram colored by
     * the first primary brand color, otherwise a deterministic palette color.
     *
     * @param array<Project> $projects
     * @return array<string, ProjectAvatar> keyed by project id
     */
    public function forProjects(array $projects): array
    {
        $manualsByProject = $this->manualsByProject($projects);
        $avatars = [];

        foreach ($projects as $project) {
            $projectId = $project->id->toString();
            $manuals = $manualsByProject[$projectId] ?? [];

            $avatars[$projectId] = ProjectAvatar::build(
                seed: $projectId,
                projectName: $project->name,
                logoPath: $this->firstLogoPath($manuals),
                brandColorHex: $this->firstBrandColorHex($manuals),
            );
        }

        return $avatars;
    }

    /**
     * @param array<Project> $projects
     * @return array<string, array<Manual>>
     */
    private function manualsByProject(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        /** @var array<Manual> $manuals */
        $manuals = $this->entityManager->createQueryBuilder()
            ->from(Manual::class, 'm')
            ->select('m')
            ->join('m.project', 'p')
            ->where('p.id IN (:projectIds)')
            ->setParameter('projectIds', array_map(
                static fn (Project $project): string => $project->id->toString(),
                $projects,
            ))
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        // Brand manuals first — their logo is the project's identity.
        usort($manuals, static function (Manual $a, Manual $b): int {
            return [$a->isBrandManual() ? 0 : 1, $a->createdAt] <=> [$b->isBrandManual() ? 0 : 1, $b->createdAt];
        });

        $byProject = [];

        foreach ($manuals as $manual) {
            $byProject[$manual->project->id->toString()][] = $manual;
        }

        return $byProject;
    }

    /**
     * @param array<Manual> $manuals
     */
    private function firstLogoPath(array $manuals): null|string
    {
        foreach ($manuals as $manual) {
            $introImage = $manual->logo->introImage();

            if ($introImage !== null) {
                return $introImage->filePath;
            }
        }

        return null;
    }

    /**
     * @param array<Manual> $manuals
     */
    private function firstBrandColorHex(array $manuals): null|string
    {
        foreach ($manuals as $manual) {
            foreach ($manual->primaryColors() as $manualColor) {
                if (!$manualColor->color->isWhite()) {
                    return $manualColor->color->hex;
                }
            }
        }

        return null;
    }
}
