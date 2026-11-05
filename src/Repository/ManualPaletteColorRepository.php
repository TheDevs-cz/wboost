<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\ManualPaletteColor;

/**
 * A manual's palette links (F7) — see {@see ProjectColorRepository}.
 */
readonly final class ManualPaletteColorRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<ManualPaletteColor> manual colour order (logo segment first), palette colour fetch-joined
     */
    public function forManual(UuidInterface $manualId): array
    {
        /** @var list<ManualPaletteColor> $links */
        $links = $this->entityManager->createQueryBuilder()
            ->select('link', 'project_color')
            ->from(ManualPaletteColor::class, 'link')
            ->join('link.projectColor', 'project_color')
            ->where('link.manual = :manualId')
            ->setParameter('manualId', $manualId->toString())
            ->orderBy('link.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $links;
    }
}
