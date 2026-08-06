<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

/**
 * The vertical band a semantically-placed element lives in.
 *
 * **This enum names the bands; it does NOT define their geometry.** The exact
 * px math (thirds / halves / full height) is a public contract owned by
 * `GridResolver` (S4-T2) and documented in its class docblock — the parser
 * only validates that the agent named a band that exists.
 */
enum PlacementArea: string
{
    case Top = 'top';
    case Upper = 'upper';
    case Middle = 'middle';
    case Lower = 'lower';
    case Bottom = 'bottom';
    case Full = 'full';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
