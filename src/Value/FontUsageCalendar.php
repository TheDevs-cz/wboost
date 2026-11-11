<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One calendar whose layout references a font face (a text style's `family`)
 * — where a designer goes to change it: step 3 of the calendar's workspace.
 */
readonly final class FontUsageCalendar
{
    public function __construct(
        public string $calendarId,
        public string $calendarName,
    ) {
    }
}
