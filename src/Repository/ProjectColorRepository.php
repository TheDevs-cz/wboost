<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\ProjectColor;

/**
 * Project palette colours (F7) — ALL of them, linked or not (the P2 palette
 * page lists unlinked ones as "Nepoužitá"). Templates / print / API read the
 * linked-only {@see \WBoost\Web\Query\GetProjectPalette}; writes go through
 * {@see \WBoost\Web\Services\Palette\ManualPaletteWriter} (DBAL).
 */
readonly final class ProjectColorRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<ProjectColor> palette order
     */
    public function forProject(UuidInterface $projectId): array
    {
        /** @var list<ProjectColor> $colors */
        $colors = $this->entityManager->createQueryBuilder()
            ->select('project_color')
            ->from(ProjectColor::class, 'project_color')
            ->where('project_color.project = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->orderBy('project_color.position', 'ASC')
            ->addOrderBy('project_color.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $colors;
    }

    public function add(ProjectColor $color): void
    {
        $this->entityManager->persist($color);
    }
}
