<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * The two shadow effects Fabric's single native `shadow` can draw — the
 * calendar effects' names (docs/calendar/effects-plan.md §3).
 */
enum CanvasShadowKind: string
{
    case DropShadow = 'dropShadow';
    case OuterGlow = 'outerGlow';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
