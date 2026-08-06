<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

/**
 * Where an element goes: the semantic {@see SemanticPlacement} plus the
 * absolute escape hatch, and — critically — **the one implementation of
 * "absolute wins"** (plan §3.4).
 *
 * The rule is PER PROPERTY, not wholesale, and the grammar's own image example
 * is what forces that reading:
 *
 *     { "kind": "image", "at": { "area": "bottom", "col": [1, 12] }, "height": 480 }
 *
 * `at` supplies x / y / width; the authored `height` overrides only the
 * height. So every consumer that needs final geometry calls
 * {@see self::resolve()} with whatever `GridResolver` computed (or `null` when
 * there is no `at`) and gets the merged {@see Rect} — the merge is never
 * re-derived in the grid resolver, the compiler, the linter and the
 * decompiler, which is exactly how four subtly different rules get born.
 *
 * The parser guarantees a placement is RESOLVABLE: either `at` is present, or
 * all of `x`, `y` and `width` are. `height` is optional in both cases (see
 * {@see Rect::$height}).
 */
readonly final class Placement
{
    public function __construct(
        public null|SemanticPlacement $at = null,
        public null|float $x = null,
        public null|float $y = null,
        public null|float $width = null,
        public null|float $height = null,
    ) {
    }

    public function hasSemanticPlacement(): bool
    {
        return $this->at !== null;
    }

    /**
     * True when the element needs no grid math at all — x, y and width were
     * all authored. (`height` is excluded on purpose: a textbox never has one.)
     */
    public function isFullyAbsolute(): bool
    {
        return $this->x !== null && $this->y !== null && $this->width !== null;
    }

    /**
     * Merge the grid-computed rect with the authored absolute values, absolute
     * winning per property.
     *
     * @param null|Rect $fromGrid what `GridResolver` made of {@see $at}; null
     *   when there is no `at` — in which case a parsed document guarantees
     *   x/y/width were all authored, so the `0.0` fallbacks below are
     *   unreachable and exist only to keep the method total.
     */
    public function resolve(null|Rect $fromGrid): Rect
    {
        // `$fromGrid->x ?? …` is null-safe: `??` suppresses the property fetch
        // on a null object, and PHPStan rejects `?->` here as redundant.
        return new Rect(
            $this->x ?? $fromGrid->x ?? 0.0,
            $this->y ?? $fromGrid->y ?? 0.0,
            $this->width ?? $fromGrid->width ?? 0.0,
            $this->height ?? $fromGrid?->height,
        );
    }

    /**
     * @return array{at: null|array{area: string, col: array{int, int}, marginX: float, marginY: float, offsetX: float, offsetY: float}, x: null|float, y: null|float, width: null|float, height: null|float}
     */
    public function toArray(): array
    {
        return [
            'at' => $this->at?->toArray(),
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
