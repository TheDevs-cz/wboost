<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Calendar;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\Calendar\CalendarActor;

/**
 * A plain history line for actions that change nothing in the document
 * ("Zkopíroval odkaz pro klienta", layout imports).
 */
readonly final class RecordCalendarHistory
{
    public function __construct(
        public UuidInterface $calendarId,
        public CalendarActor $actor,
        public string $description,
    ) {
    }
}
