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
}
