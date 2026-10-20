<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * A shape the designer opened up for recolouring — fill it through
 * render_variant / export_variant `shapeColors: {"<id>": "#rrggbb"}`. The
 * MCP twin of the REST `variants[].shapeInputs[]`.
 */
readonly final class VariantShapeInputResponse
{
    /**
     * @param list<string> $colorOptions lowercase `#rrggbb`: `defaultColor`
     *        first, then the designer's list. Without `freeColor` a colour
     *        outside this list is refused.
     */
    public function __construct(
        public string $id,
        public null|string $name,
        /** `fill`, `stroke` (the border) or `both` — what one colour repaints. */
        public string $target,
        public string $defaultColor,
        public array $colorOptions,
        public bool $freeColor,
        public null|VariantInputFrameResponse $frame,
    ) {
    }
}
