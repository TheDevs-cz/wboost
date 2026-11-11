<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * What `manage_calendar` and `set_calendar_layout` answer: the calendar's
 * DESIGN as it is stored now, fully materialised (every default filled in by
 * the calendar model), plus what this call did.
 *
 * Every map is a JSON object even when empty — `stdClass`, as in
 * {@see GetCalendarResponse}.
 */
readonly final class CalendarDesignResponse
{
    /**
     * @param list<string> $changed
     * @param list<string> $notes
     * @param list<CalendarChangeResponse> $recorded
     */
    public function __construct(
        public string $calendarId,
        public string $projectId,
        public string $name,
        public int $year,
        /** "week" (7 days per page) | "fortnight" (14 days per page). */
        public string $type,
        public bool $finished,
        /** The workspace step the web opens the calendar in: 1 Typ, 2 Rozvržení, 3 Úpravy prvků, 4 Obsah, 5 Náhled. */
        public int $step,
        /** +1 with every save that changed something. */
        public int $revision,
        /** ISO 8601. */
        public string $updatedAt,
        /** "photoLeft" | "photoRight" | "twoSided"; null while the calendar has no layout. */
        public null|string $preset,
        /** The ten feature switches, all present. */
        public \stdClass $features,
        /** {bleed, bleedMm, marks}. */
        public \stdClass $print,
        /** Trim size in mm {width, height, bandHeight, holes}. */
        public \stdClass $page,
        /** {v, boxes, order, styles} — see set_calendar_layout; null while there is no layout. */
        public null|\stdClass $layout,
        /** What this call changed: design keys (name, year, type, print, features, preset, layout, step, finished…) and "content" when dated items moved with the year. Empty = nothing changed, nothing was saved. */
        public array $changed,
        /** What happened beyond the literal request: values brought into their limits, a mirrored or reset layout, items dropped by a year change. Read them. */
        public array $notes,
        /** The history lines this call added (newest first) — what the user reads in "Historie". */
        public array $recorded,
        /** The web workspace — for the user to open. */
        public string $url,
        /** Only from manage_calendar "client_link": the public link the client fills the calendar in through. Whoever has it can write content without an account — hand it to the user, do not publish it. */
        public null|string $clientUrl = null,
        /** Only from manage_calendar "export_pdf" / "export_status": the PDF export job. */
        public null|CalendarExportResponse $export = null,
    ) {
    }
}
