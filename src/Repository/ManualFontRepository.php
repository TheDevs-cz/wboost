<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\ManualFont;
use WBoost\Web\Exceptions\ManualFontNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class ManualFontRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ManualFontNotFound
     */
    public function get(UuidInterface $manualFontId): ManualFont
    {
        $font = $this->entityManager->find(ManualFont::class, $manualFontId);

        if ($font instanceof ManualFont) {
            return $font;
        }

        throw new ManualFontNotFound();
    }

    public function add(ManualFont $font): void
    {
        $this->entityManager->persist($font);
    }

    public function remove(ManualFont $font): void
    {
        $this->entityManager->remove($font);
    }

    public function count(UuidInterface $manualId): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(manual_font)')
            ->from(ManualFont::class, 'manual_font')
            ->where('manual_font.manual = :manualId')
            ->setParameter('manualId', $manualId)
            ->getQuery()
            ->getSingleScalarResult();

        assert(is_int($count));

        return $count;
    }
}
