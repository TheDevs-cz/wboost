<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

enum LogoTypeVariant: string
{
    case Horizontal = 'horizontal';
    case Vertical = 'vertical';
    case HorizontalWithClaim = 'horizontalWithClaim';
    case VerticalWithClaim = 'verticalWithClaim';
    case Symbol = 'symbol';
}
