<?php

declare(strict_types=1);

namespace WBoost\Web\Value\Calendar;

use DateTimeImmutable;
use WBoost\Web\Entity\Calendar;

/**
 * One card of the calendar list: the calendar and its newest history entry
 * (null when it has none) — {@see \WBoost\Web\Query\GetCalendars}.
 */
readonly final class CalendarListRow
{
    public function __construct(
        public Calendar $calendar,
        public null|CalendarAuthorKind $lastChangeBy,
        public null|DateTimeImmutable $lastChangeAt,
    ) {
    }
}
