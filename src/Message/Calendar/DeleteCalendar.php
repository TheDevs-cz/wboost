<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Calendar;

use Ramsey\Uuid\UuidInterface;

/** Removes the calendar with its assets, history and stored files. */
readonly final class DeleteCalendar
{
    public function __construct(
        public UuidInterface $calendarId,
    ) {
    }
}
