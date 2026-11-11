<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * `get_calendar` answer: the calendar's settings, its layout with every
 * style, its pages, its whole content and the files uploaded to it.
 *
 * Every map is a JSON OBJECT even when empty (`{}`), which is why they are
 * `stdClass` here: a PHP array would encode an empty collection as `[]` and
 * an agent could not tell "no items" from "a list".
 */
readonly final class GetCalendarResponse
{
    /**
     * @param list<string> $writableCollections
     * @param null|list<array{index: int, key: string, kind: string, title: string, subtitle: string, from?: string, to?: string}> $pages
     * @param list<array{family: string, fontName: string, faceName: string, weight: int, style: string}> $fonts
     * @param list<string> $palette
     * @param list<CalendarChangeResponse> $history
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $projectName,
        public string $name,
        public int $year,
        /** "week" | "fortnight". */
        public string $type,
        public bool $finished,
        /** The workspace step the web opens the calendar in: 1 Typ, 2 Rozvržení, 3 Úpravy prvků, 4 Obsah, 5 Náhled. */
        public int $step,
        /** +1 on every successful save — compare it to notice somebody else's change between two reads. */
        public int $revision,
        /** ISO 8601. */
        public string $updatedAt,
        /** What this account is for this calendar: "designer" (may edit the project) or "client" (project shared for viewing). */
        public string $role,
        /** The content collections update_calendar_content accepts from this account. */
        public array $writableCollections,
        /** "photoLeft" | "photoRight" | "twoSided"; null until a layout is chosen. */
        public null|string $preset,
        /** The ten feature switches of step 1. Content of a switched-off feature is stored but not printed. */
        public \stdClass $features,
        /** {bleed, bleedMm, marks}. */
        public \stdClass $print,
        /** Trim size in mm {width, height, bandHeight, holes}. */
        public \stdClass $page,
        /** {v, boxes: {<box>: {x, y, w, h, hidden?}} in mm from the top-left trim corner, order: [...top layer first], styles: {…}}; null until a layout exists. Patch it with set_calendar_layout. */
        public null|\stdClass $layout,
        /** Every page in print order: {index, key, kind, title, subtitle, from?, to?}; null when the page list could not be computed. */
        public null|array $pages,
        /** {v: 1, <collection>: {<id>: item}} — see update_calendar_content for every item shape. */
        public \stdClass $content,
        /** <assetId>: {url, previewUrl, width, height, mime, name, description, kind} — the ids content items and styles refer to. */
        public \stdClass $assets,
        /** The project's font faces; a text style's `family` is one of these `family` strings exactly, or null. */
        public array $fonts,
        /** The project's colours, "#RRGGBB". */
        public array $palette,
        /** The newest lines of the change history, newest first. */
        public array $history,
        /** The web workspace — for the user to open. */
        public string $url,
    ) {
    }
}
