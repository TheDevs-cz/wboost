<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

/**
 * An axis-aligned rectangle in canvas pixels, top-left origin — the same space
 * as `EditorTextInput`'s / `EditorImageInput`'s `frame` and the variant's
 * `width`/`height`.
 *
 * Shared vocabulary between the parser (absolute escape hatch), `GridResolver`
 * (S4-T2, semantic → px), the compiler (S4-T4, Fabric `left`/`top`/`width`)
 * and the linter (S4-T6, bounds/overlap checks) so none of them invents its
 * own tuple.
 */
readonly final class Rect
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        /**
         * Null = "not authored, let the renderer decide".
         *
         * A textbox NEVER carries a height: Fabric computes it from the
         * wrapped text and authoring one is forbidden (plan §4.2 invariant 6).
         * An image without an authored height gets one from its area band or
         * its asset's aspect ratio — the compiler's call.
         */
        public null|float $height,
    ) {
    }

    public function right(): float
    {
        return $this->x + $this->width;
    }

    /**
     * Bottom edge, or null when the height is renderer-decided.
     */
    public function bottom(): null|float
    {
        return $this->height === null ? null : $this->y + $this->height;
    }
}
