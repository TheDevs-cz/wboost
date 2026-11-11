<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\CalendarHistoryEntry;

readonly final class CalendarHistoryRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(CalendarHistoryEntry $entry): void
    {
        $this->entityManager->persist($entry);
    }

    /**
     * @return list<CalendarHistoryEntry> newest first
     */
    public function latest(UuidInterface $calendarId, int $limit): array
    {
        /** @var list<CalendarHistoryEntry> $entries */
        $entries = $this->entityManager->createQueryBuilder()
            ->select('entry')
            ->from(CalendarHistoryEntry::class, 'entry')
            ->where('entry.calendar = :calendarId')
            ->setParameter('calendarId', $calendarId->toString())
            ->orderBy('entry.createdAt', 'DESC')
            ->addOrderBy('entry.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $entries;
    }
}
