<?php

declare(strict_types=1);

namespace WBoost\Web\Value\Calendar;

/**
 * 7 dní (one week per page) or 14 dní (two weeks per page, one fixed
 * two-sided layout) — docs/calendar/architecture.md §B.1.
 */
enum CalendarType: string
{
    case Week = 'week';
    case Fortnight = 'fortnight';

    /**
     * The layout presets a calendar of this type may carry.
     *
     * @return list<string>
     */
    public function presets(): array
    {
        return match ($this) {
            self::Week => ['photoLeft', 'photoRight'],
            self::Fortnight => ['twoSided'],
        };
    }
}
