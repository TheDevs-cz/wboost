<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\ManualPaletteColor;
use WBoost\Web\Entity\ProjectColor;
use WBoost\Web\Value\ManualColor;

/**
 * The project palette as templates, print and the API/MCP see it (F7, R2):
 * palette order (D5), and **only the colours at least one manual of the
 * project links**.
 *
 * Why linked-only: release R1 never deleted palette colours, and the old
 * per-manual colours page still edits through the palette in R2 — a print
 * edit that conflicts with a shared colour splits it, a removed custom
 * colour leaves its palette colour behind. Offering those leftovers as
 * swatches (or letting one win the print "first CMYK") would change the
 * templates although no manual shows the colour. The P2 palette page lists
 * them as "Nepoužitá" instead (via `ProjectColorRepository::forProject()`,
 * which returns every colour). Revisit when the palette page lets users
 * curate colours no manual uses.
 */
readonly final class GetProjectPalette
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<ProjectColor> palette order, linked colours only
     */
    public function forProject(UuidInterface $projectId): array
    {
        /** @var list<ProjectColor> $colors */
        $colors = $this->entityManager->createQueryBuilder()
            ->select('project_color')
            ->from(ProjectColor::class, 'project_color')
            ->where('project_color.project = :projectId')
            ->andWhere(sprintf('EXISTS (SELECT 1 FROM %s link WHERE link.projectColor = project_color)', ManualPaletteColor::class))
            ->setParameter('projectId', $projectId->toString())
            ->orderBy('project_color.position', 'ASC')
            ->addOrderBy('project_color.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $colors;
    }

    /**
     * {@see forProject()} as swatch / print entries.
     *
     * @return list<ManualColor>
     */
    public function colorsForProject(UuidInterface $projectId): array
    {
        return array_map(static fn (ProjectColor $color): ManualColor => $color->manualColor(), $this->forProject($projectId));
    }
}
