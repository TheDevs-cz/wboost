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

    public function getUploadInputsCount(): int
    {
        return match ($this) {
            self::Layout1 => 4,
            self::Layout2 => 3,
            self::Layout3 => 3,
            self::Layout4 => 5,
            self::Layout5 => 4,
            self::Layout6 => 6,
        };
    }
}
