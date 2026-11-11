<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Value\Calendar\CalendarAuthorKind;
use WBoost\Web\Value\Calendar\CalendarListRow;

/**
 * The calendar list of a project: every calendar (newest first) with its
 * newest history entry — who changed it last and when — read in one lateral
 * join instead of one query per card.
 */
readonly final class GetCalendars
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CalendarRepository $calendarRepository,
    ) {
    }

    /**
     * @return list<CalendarListRow>
     */
    public function forProject(UuidInterface $projectId): array
    {
        $calendars = $this->calendarRepository->forProject($projectId);

        if ($calendars === []) {
            return [];
        }

        $latest = [];

        $rows = $this->entityManager->getConnection()->iterateAssociative(
            'SELECT c.id AS calendar_id, h.author_kind, h.created_at
             FROM calendar c
             JOIN LATERAL (
                 SELECT e.author_kind, e.created_at
                 FROM calendar_history e
                 WHERE e.calendar_id = c.id
                 ORDER BY e.created_at DESC, e.id DESC
                 LIMIT 1
             ) h ON true
             WHERE c.project_id = :projectId',
            ['projectId' => $projectId->toString()],
        );

        foreach ($rows as $row) {
            $calendarId = $row['calendar_id'];
            $kind = is_string($row['author_kind']) ? CalendarAuthorKind::tryFrom($row['author_kind']) : null;

            if (!is_string($calendarId) || $kind === null || !is_string($row['created_at'])) {
                continue;
            }

            $latest[strtolower($calendarId)] = [$kind, new DateTimeImmutable($row['created_at'])];
        }

        $list = [];

        foreach ($calendars as $calendar) {
            [$kind, $at] = $latest[strtolower($calendar->id->toString())] ?? [null, null];
            $list[] = new CalendarListRow($calendar, $kind, $at);
        }

        return $list;
    }
}
