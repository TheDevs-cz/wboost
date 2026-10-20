<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

use WBoost\Web\Value\ShapeColorTarget;

/**
 * A shape's `userColor` block — the DSL face of the shape popover's "Barva pro
 * uživatele": the end user may repaint the shape (`target`: fill, stroke or
 * both, in ONE colour) with one of `colors` — the designed colour is always
 * allowed on top — or with any colour when `free` is true. Compiles to the
 * canvas props `colorChoice` / `allowedColors` / `colorFree` that
 * {@see \WBoost\Web\Value\ShapeColorInput} reads.
 */
readonly final class ShapeUserColor
{
    /**
     * @param list<string> $colors lowercase `#rrggbb`
     */
    public function __construct(
        public ShapeColorTarget $target,
        public array $colors,
        public bool $free,
    ) {
    }

    /**
     * @return array{target: string, colors: list<string>, free: bool}
     */
    public function toArray(): array
    {
        return [
            'target' => $this->target->value,
            'colors' => $this->colors,
            'free' => $this->free,
        ];
    }
}
