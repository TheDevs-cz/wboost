<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Calendar;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\Calendar\CalendarActor;

/** A new client token — the old link stops working at once. */
readonly final class RegenerateCalendarClientLink
{
    public function __construct(
        public UuidInterface $calendarId,
        public CalendarActor $actor,
    ) {
    }
}
