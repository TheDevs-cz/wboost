<?php

declare(strict_types=1);

namespace WBoost\Web\Value\Calendar;

/**
 * One rendered calendar page ({@see \WBoost\Web\Services\Calendar\CalendarPageRenderer}).
 */
readonly final class CalendarPageRender
{
    /**
     * @param array{index: int, key: string, kind: string, title: string, subtitle: string, from?: string, to?: string} $page
     * @param list<array{index: int, key: string, kind: string, title: string, subtitle: string, from?: string, to?: string}> $pages every page of the calendar
     * @param list<array{page: string, kind: string, message: string}> $warnings warnings of the rendered page
     * @param array{x: float, y: float, w: float, h: float} $view what the picture shows, in mm from the top-left trim corner
     */
    public function __construct(
        public string $bytes,
        public int $width,
        public int $height,
        public array $page,
        public array $pages,
        public array $warnings,
        public array $view,
    ) {
    }
}
