<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

/**
 * The content a new calendar starts with: nothing but the four built-in
 * waste kinds, switched off (docs/calendar/architecture.md §B.6, deviation
 * D5). The names mirror `BUILTIN_WASTE` in `assets/calendar/model/defaults.js`
 * — a contract test pins the two tables together.
 */
final class CalendarContentSeed
{
    /** Built-in waste kind id → name, in list order. `sklo` exists in JS but is not seeded. */
    public const array BUILTIN_WASTE = [
        'kom' => 'Komunální odpad',
        'bio' => 'Bio odpad',
        'plast' => 'Plast',
        'papir' => 'Papír',
    ];

    /**
     * @return array{v: int, wasteKinds: array<string, array<string, mixed>>}
     */
    public static function initial(): array
    {
        $kinds = [];
        $position = 0;

        foreach (self::BUILTIN_WASTE as $id => $name) {
            $kinds[$id] = [
                'builtin' => $id,
                'name' => $name,
                'on' => false,
                'place' => '',
                'mode' => 'rule',
                'weekdays' => [0],
                'interval' => 'w',
                'from' => null,
                'to' => null,
                'dates' => [],
                'pos' => $position++,
            ];
        }

        return ['v' => 1, 'wasteKinds' => $kinds];
    }
}
