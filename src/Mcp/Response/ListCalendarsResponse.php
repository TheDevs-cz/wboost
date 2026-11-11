<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * `list_calendars` answer: the calendars of one project, or of every project
 * the account can reach — ordered by project name, then newest first.
 */
readonly final class ListCalendarsResponse
{
    /**
     * @param list<CalendarSummaryResponse> $calendars
     */
    public function __construct(
        /** The project the listing was narrowed to; null = every reachable project. */
        public null|string $projectId,
        public array $calendars,
    ) {
    }
}
