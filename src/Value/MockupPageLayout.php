<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

enum MockupPageLayout: string
{
    case Layout1 = 'layout-1';
    case Layout2 = 'layout-2';
    case Layout3 = 'layout-3';
    case Layout4 = 'layout-4';
    case Layout5 = 'layout-5';
    case Layout6 = 'layout-6';
    case Layout7 = 'layout-7';
    case Layout8 = 'layout-8';
    case Layout9 = 'layout-9';
    case Layout10 = 'layout-10';
    case Layout11 = 'layout-11';

    public function uploadInputsCount(): int
    {
        return match ($this) {
            self::Layout1 => 4,
            self::Layout2 => 3,
            self::Layout3 => 3,
            self::Layout4 => 5,
            self::Layout5 => 4,
            self::Layout6 => 6,
            self::Layout7 => 1,
            self::Layout8 => 2,
            self::Layout9 => 2,
            self::Layout10 => 3,
            self::Layout11 => 3,
        };
    }
}
