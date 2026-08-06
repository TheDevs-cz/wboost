<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

/**
 * Horizontal alignment of a text element — the four values Fabric's
 * `textAlign` understands, so the compiler passes the value straight through.
 */
enum TextAlign: string
{
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';
    case Justify = 'justify';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
