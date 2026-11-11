<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Exceptions\CalendarNotFound;

readonly final class CalendarRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CalendarNotFound
     */
    public function get(UuidInterface $calendarId): Calendar
    {
        $calendar = $this->entityManager->find(Calendar::class, $calendarId);

        if ($calendar instanceof Calendar) {
            return $calendar;
        }

        throw new CalendarNotFound();
    }

    /**
     * The row locked `FOR UPDATE` and refreshed — concurrent saves of one
     * calendar queue up here, which is what makes the item-wise content merge
     * safe. Must run inside a transaction (the message bus opens one).
     *
     * @throws CalendarNotFound
     */
    public function getForUpdate(UuidInterface $calendarId): Calendar
    {
        $calendar = $this->entityManager->find(Calendar::class, $calendarId, LockMode::PESSIMISTIC_WRITE);

        if ($calendar instanceof Calendar) {
            return $calendar;
        }

        throw new CalendarNotFound();
    }

    /**
     * @throws CalendarNotFound
     */
    public function getByClientToken(string $token): Calendar
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new CalendarNotFound();
        }

        $calendar = $this->entityManager->getRepository(Calendar::class)->findOneBy(['clientToken' => $token]);

        if ($calendar instanceof Calendar && hash_equals($calendar->clientToken, $token)) {
            return $calendar;
        }

        throw new CalendarNotFound();
    }

    /**
     * @return list<Calendar> newest first
     */
    public function forProject(UuidInterface $projectId): array
    {
        /** @var list<Calendar> $calendars */
        $calendars = $this->entityManager->createQueryBuilder()
            ->select('calendar')
            ->from(Calendar::class, 'calendar')
            ->where('calendar.project = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->orderBy('calendar.createdAt', 'DESC')
            ->addOrderBy('calendar.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $calendars;
    }

    public function add(Calendar $calendar): void
    {
        $this->entityManager->persist($calendar);
    }

    public function remove(Calendar $calendar): void
    {
        $this->entityManager->remove($calendar);
    }
}
