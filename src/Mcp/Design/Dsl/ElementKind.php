<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

/**
 * The five things a design document can contain (plan §3.4, plus `shape`).
 *
 * The `kind` discriminator is what the parser branches on, so the allowed
 * values are also what an unknown-kind error message lists — that is the whole
 * reason {@see self::values()} exists rather than a literal in the parser.
 */
enum ElementKind: string
{
    case Text = 'text';
    case Image = 'image';
    case Shape = 'shape';
    case Background = 'background';
    case Container = 'container';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
