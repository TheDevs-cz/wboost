<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * `get_calendar` answer: the calendar's settings, its whole content and the
 * files uploaded to it.
 *
 * Every map is a JSON OBJECT even when empty (`{}`), which is why they are
 * `stdClass` here: a PHP array would encode an empty collection as `[]` and
 * an agent could not tell "no items" from "a list".
 *
 * The page layout's `styles` (fonts, colours, part offsets — tens of kB the
 * calendar JavaScript owns) are left out on purpose: nothing an agent may
 * change through MCP lives there.
 */
readonly final class GetCalendarResponse
{
    /**
     * @param list<string> $writableCollections
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
        /** +1 on every successful save — compare it to notice somebody else's change between two reads. */
        public int $revision,
        /** ISO 8601. */
        public string $updatedAt,
        /** What this account is for this calendar: "designer" (may edit the project) or "client" (project shared for viewing). */
        public string $role,
        /** The content collections update_calendar_content accepts from this account. */
        public array $writableCollections,
        /** "photoLeft" | "photoRight" | "twoSided"; null until the designer chooses a layout. */
        public null|string $preset,
        /** Feature switches of step 1 (captions, weekNumbers, moon, timeChange, seasons, waste, events, shifts, specialPages, qr). `{}` = never saved: captions, weekNumbers and moon on, the rest off. Content of a switched-off feature is stored but not printed. */
        public \stdClass $features,
        /** {bleed, bleedMm, marks}; `{}` = never saved (5 mm bleed with crop marks). */
        public \stdClass $print,
        /** Trim size in mm {width, height, bandHeight, holes}; `{}` = never saved (310 × 145 mm). */
        public \stdClass $page,
        /** {boxes: {<box>: {x, y, w, h, hidden?}} in mm from the top-left trim corner, order: [...top layer first]}; null until a layout exists. Read-only here. */
        public null|\stdClass $layout,
        /** {v: 1, <collection>: {<id>: item}} — see update_calendar_content for every item shape. */
        public \stdClass $content,
        /** <assetId>: {url, previewUrl, width, height, mime, name, description, kind} — the ids content items refer to. */
        public \stdClass $assets,
        /** The newest lines of the change history, newest first. */
        public array $history,
        /** The web workspace — for the user to open. */
        public string $url,
    ) {
    }
}
