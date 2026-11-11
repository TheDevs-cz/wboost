<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\CalendarExport;
use WBoost\Web\Exceptions\CalendarExportNotFound;

readonly final class CalendarExportRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CalendarExportNotFound
     */
    public function get(UuidInterface $exportId): CalendarExport
    {
        $export = $this->entityManager->find(CalendarExport::class, $exportId);

        if ($export instanceof CalendarExport) {
            return $export;
        }

        throw new CalendarExportNotFound();
    }

    /**
     * The export only when it belongs to THIS calendar — an export id is
     * never an address by itself (no reading across calendars or links).
     *
     * @throws CalendarExportNotFound
     */
    public function getOfCalendar(UuidInterface $calendarId, UuidInterface $exportId): CalendarExport
    {
        $export = $this->get($exportId);

        if (!$export->calendar->id->equals($calendarId)) {
            throw new CalendarExportNotFound();
        }

        return $export;
    }

    public function add(CalendarExport $export): void
    {
        $this->entityManager->persist($export);
    }

    public function remove(CalendarExport $export): void
    {
        $this->entityManager->remove($export);
    }

    /**
     * @return list<CalendarExport> newest first
     */
    public function forCalendar(UuidInterface $calendarId): array
    {
        /** @var list<CalendarExport> $exports */
        $exports = $this->entityManager->createQueryBuilder()
            ->select('export')
            ->from(CalendarExport::class, 'export')
            ->where('export.calendar = :calendarId')
            ->setParameter('calendarId', $calendarId->toString())
            ->orderBy('export.createdAt', 'DESC')
            ->addOrderBy('export.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $exports;
    }
}
