<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Calendar;

use Ramsey\Uuid\UuidInterface;

/**
 * Vytvořit kalendář: a calendar named "Stolní kalendář {next year}", type
 * 7 dní, with the seeded content and a fresh client link — no dialog.
 */
readonly final class CreateCalendar
{
    public function __construct(
        public UuidInterface $calendarId,
        public UuidInterface $projectId,
        public null|UuidInterface $userId,
    ) {
    }
}
