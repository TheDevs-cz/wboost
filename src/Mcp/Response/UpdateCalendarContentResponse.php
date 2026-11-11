<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * `update_calendar_content` answer. The call is atomic: when this comes back
 * every operation was accepted (changed or already as sent); a refused call
 * is an error and stored nothing.
 */
readonly final class UpdateCalendarContentResponse
{
    /**
     * @param list<CalendarOperationResultResponse> $results
     * @param list<CalendarChangeResponse> $recorded
     */
    public function __construct(
        public string $calendarId,
        /** The calendar's revision after this save. */
        public int $revision,
        /** ISO 8601. */
        public string $updatedAt,
        /** "designer" | "client" — the role the changes were recorded under. */
        public string $role,
        /** One entry per operation, in the order sent. */
        public array $results,
        /** The touched collections as stored now: {<collection>: {<id>: item}}. */
        public \stdClass $content,
        /** The history lines this call added (newest first) — what the user will read in "Historie". */
        public array $recorded,
    ) {
    }
}
