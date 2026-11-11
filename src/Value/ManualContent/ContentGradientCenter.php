<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

/**
 * Where a radial gradient starts — the nine points of a 3 × 3 grid.
 */
enum ContentGradientCenter: string
{
    case TopLeft = 'top-left';
    case Top = 'top';
    case TopRight = 'top-right';
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';
    case BottomLeft = 'bottom-left';
    case Bottom = 'bottom';
    case BottomRight = 'bottom-right';

    /** CSS `<position>` (x y). */
    public function cssPosition(): string
    {
        return match ($this) {
            self::TopLeft => '0% 0%',
            self::Top => '50% 0%',
            self::TopRight => '100% 0%',
            self::Left => '0% 50%',
            self::Center => '50% 50%',
            self::Right => '100% 50%',
            self::BottomLeft => '0% 100%',
            self::Bottom => '50% 100%',
            self::BottomRight => '100% 100%',
        };
    }
}
