<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

enum LogoColorVariant: string
{
    case DarkBackground = 'darkBackground';
    case LightBackground = 'lightBackground';
    case OneColor = 'oneColor';
    case BlackBackground = 'blackBackground';
    case WhiteBackground = 'whiteBackground';

    /** Czech suffix for download file names ("<manual> - <logo> - <this>"). */
    public function label(): string
    {
        return match ($this) {
            self::DarkBackground => 'na tmavém pozadí',
            self::LightBackground => 'na světlém pozadí',
            self::OneColor => 'jednobarevné',
            self::BlackBackground => 'na černém pozadí',
            self::WhiteBackground => 'na bílém pozadí',
        };
    }
}
