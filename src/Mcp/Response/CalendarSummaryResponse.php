<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * One desk calendar in a `list_calendars` answer — enough to pick the right
 * one and to tell the user where it stands, without its content.
 */
readonly final class CalendarSummaryResponse
{
    public function __construct(
        /** The id get_calendar and update_calendar_content take. */
        public string $id,
        public string $projectId,
        public string $projectName,
        public string $name,
        /** The calendar year (the pages run from the week of 1 January to the week of 31 December). */
        public int $year,
        /** "week" = one week per page (7 dní), "fortnight" = two weeks per page (14 dní). */
        public string $type,
        /** false = the designer has not finished the look yet ("Rozpracovaný vzhled"). */
        public bool $finished,
        /** false = no page layout chosen yet: the calendar has no pages to look at, but content can already be stored. */
        public bool $hasLayout,
        /** ISO 8601, the last successful save. */
        public string $updatedAt,
        /** "designer" | "client" — who made the newest entry of the change history; null when there is none. */
        public null|string $lastChangeBy,
        /** ISO 8601 of that entry. */
        public null|string $lastChangeAt,
        /** The web workspace of this calendar — for the user to open, not for fetching. */
        public string $url,
    ) {
    }
}
