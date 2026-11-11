<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * The JSON half of the `render_calendar_page` reply — it travels in front of
 * the picture, like {@see RenderVariantResponse}.
 */
readonly final class RenderCalendarPageResponse
{
    /**
     * @param array{x: float, y: float, w: float, h: float} $viewMm
     * @param list<string> $warnings
     * @param list<string> $pageKeys
     */
    public function __construct(
        public string $calendarId,
        public string $calendarName,
        /** The rendered page: its key, kind ("cover" | "special" | "week" | "back"), title and — for a week page — date range. */
        public string $page,
        public string $kind,
        public string $title,
        public string $subtitle,
        public null|string $from,
        public null|string $to,
        /** Mime type of the image block. */
        public string $format,
        /** Pixel size of the returned picture. */
        public int $width,
        public int $height,
        /** What the picture shows, in mm from the top-left trim corner: x / y are negative when the bleed (or the crop marks) is included. */
        public array $viewMm,
        /** px per mm of the picture — multiply a box's mm by it (after subtracting viewMm.x / y) to find it in the image. */
        public float $pxPerMm,
        /** Things to act on: a portrait photo in a landscape frame, a photo below print resolution, icons or event texts that do not fit. Czech sentences, as the web shows them. */
        public array $warnings,
        /** Every page key of the calendar, in print order. */
        public array $pageKeys,
    ) {
    }
}
