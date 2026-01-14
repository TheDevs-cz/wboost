<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

enum DietCode: string
{
    case Two = '2';
    case Three = '3';
    case Nine = '9';
    case NineTwo = '9/2';

    public function label(): string
    {
        return match ($this) {
            self::Two => 'Dieta 2',
            self::Three => 'Dieta 3',
            self::Nine => 'Dieta 9',
            self::NineTwo => 'Dieta 9/2',
        };
    }

    public function shortLabel(): string
    {
        return $this->value;
    }
}
