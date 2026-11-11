<?php

declare(strict_types=1);

namespace WBoost\Web\Value\Calendar;

/**
 * Who made a change: the designer (`project_edit`) or the client (holder of
 * the client link, or a member the project is only shared with for reading).
 */
enum CalendarAuthorKind: string
{
    case Designer = 'designer';
    case Client = 'client';

    /** The name the history list shows. */
    public function label(): string
    {
        return match ($this) {
            self::Designer => 'Grafik',
            self::Client => 'Klient',
        };
    }
}
