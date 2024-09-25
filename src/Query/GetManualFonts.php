<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\ManualFont;

readonly final class GetManualFonts
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<ManualFont>
     */
    public function allForManual(UuidInterface $manualId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->from(ManualFont::class, 'manual_font')
            ->select('manual_font, font')
            ->join('manual_font.manual', 'manual')
            ->join('manual_font.font', 'font')
            ->where('manual.id = :manualId')
            ->orderBy('manual_font.position', 'ASC')
            ->setParameter('manualId', $manualId)
            ->getQuery()
            ->getResult();
    }
}
