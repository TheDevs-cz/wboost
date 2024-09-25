<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Font;
use WBoost\Web\Exceptions\FontNotFound;

readonly final class GetFonts
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws FontNotFound
     */
    public function byName(UuidInterface $projectId, string $fontName): Font
    {
        try {
            $row = $this->entityManager->createQueryBuilder()
                ->from(Font::class, 'f')
                ->join('f.project', 'p')
                ->select('f', 'p')
                ->where('p.id = :projectId')
                ->setParameter('projectId', $projectId->toString())
                ->andWhere('f.name = :fontName')
                ->setParameter('fontName', $fontName)
                ->getQuery()
                ->getSingleResult();

            assert($row instanceof Font);
            return $row;
        } catch (NoResultException) {
            throw new FontNotFound();
        }
    }

    /**
     * @return array<Font>
     */
    public function allForUser(UuidInterface $userId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->from(Font::class, 'f')
            ->select('f')
            ->join('f.project', 'p')
            ->where('p.owner = :userId')
            ->setParameter('userId', $userId->toString())
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<Font>
     */
    public function allForProject(UuidInterface $projectId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->from(Font::class, 'f')
            ->select('f')
            ->join('f.project', 'p')
            ->where('p.id = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->orderBy('f.name')
            ->getQuery()
            ->getResult();
    }
}
