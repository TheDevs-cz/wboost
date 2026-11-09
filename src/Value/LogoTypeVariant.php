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

    /** Czech name, as the manual's own page headings ({@see ManualPage}) say it — used in download file names. */
    public function label(): string
    {
        return match ($this) {
            self::Horizontal => 'Horizontální logotyp',
            self::Vertical => 'Vertikální logotyp',
            self::HorizontalWithClaim => 'Horizontální logotyp se sloganem',
            self::VerticalWithClaim => 'Vertikální logotyp se sloganem',
            self::Symbol => 'Symbol',
        };
    }
}
