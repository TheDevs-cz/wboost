<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

/**
 * The `at` block — placement expressed in layout intent rather than pixels, so
 * one design document works across 1:1, 4:5, 9:16 and A4 without the agent
 * doing arithmetic.
 *
 * Vertical position comes from {@see $area} (a named band) nudged by
 * {@see $offsetY}; horizontal position from a **12-column grid**
 * ({@see $colStart}..{@see $colEnd}, both inclusive, 1-based) inset by
 * {@see $marginX} and nudged by {@see $offsetX}.
 *
 * **No geometry lives here.** Turning this into a {@see Rect} is `GridResolver`
 * (S4-T2); this VO only carries what the agent wrote, already validated.
 *
 * ⚠️ `at.row` is deliberately NOT part of v1: vertical placement is `area` +
 * `offsetY`, and inventing a row count would freeze a contract the geometry
 * task has not designed yet. Because unknown keys are rejected, an agent that
 * writes `row` is told immediately instead of being silently ignored. S4-T2
 * adding it means adding a property here AND to
 * {@see DslParser::AT_KEYS} — one place each.
 */
readonly final class SemanticPlacement
{
    public const int GRID_COLUMNS = 12;

    public function __construct(
        public PlacementArea $area,
        /** First occupied grid column, 1-based, 1..12. */
        public int $colStart = 1,
        /** Last occupied grid column, inclusive, {@see $colStart}..12. */
        public int $colEnd = self::GRID_COLUMNS,
        /** Horizontal inset applied to BOTH sides of the grid span, px, ≥ 0. */
        public float $marginX = 0.0,
        /** Vertical inset applied to BOTH ends of the area band, px, ≥ 0. */
        public float $marginY = 0.0,
        /** Free horizontal nudge after the grid math, px, may be negative. */
        public float $offsetX = 0.0,
        /** Free vertical nudge after the area math, px, may be negative. */
        public float $offsetY = 0.0,
    ) {
    }

    /**
     * How many of the 12 columns the element spans (inclusive on both ends).
     */
    public function columnSpan(): int
    {
        return $this->colEnd - $this->colStart + 1;
    }

    /**
     * @return array{area: string, col: array{int, int}, marginX: float, marginY: float, offsetX: float, offsetY: float}
     */
    public function toArray(): array
    {
        return [
            'area' => $this->area->value,
            'col' => [$this->colStart, $this->colEnd],
            'marginX' => $this->marginX,
            'marginY' => $this->marginY,
            'offsetX' => $this->offsetX,
            'offsetY' => $this->offsetY,
        ];
    }
}
