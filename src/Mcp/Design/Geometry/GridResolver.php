<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Geometry;

use WBoost\Web\Mcp\Design\Dsl\CanvasSpec;
use WBoost\Web\Mcp\Design\Dsl\Placement;
use WBoost\Web\Mcp\Design\Dsl\PlacementArea;
use WBoost\Web\Mcp\Design\Dsl\Rect;
use WBoost\Web\Mcp\Design\Dsl\SemanticPlacement;

/**
 * Turns a {@see SemanticPlacement} (`at: {area, col, marginX, marginY,
 * offsetX, offsetY}`) into canvas pixels — the one place the 12-column grid
 * and the named vertical bands are defined.
 *
 * ## THE CONTRACT
 *
 * This math is **public**: an authoring agent writes against it, the Skill
 * (S6-T3) documents it, and the decompiler (S4-T5) has to invert it. Changing
 * a number here silently moves every semantically-placed element in every
 * design ever authored. Treat it like a wire format.
 *
 * Given a canvas `W × H` and `at = {area, colStart, colEnd, marginX, marginY,
 * offsetX, offsetY}` (`colStart`/`colEnd` 1-based and INCLUSIVE, so `[1, 12]`
 * is all twelve columns and `[1, 1]` is exactly one):
 *
 *     VERTICAL — the area band, as a fraction of H
 *
 *         full   -> [0,   1  ]        the whole canvas
 *         top    -> [0,   1/3]  \
 *         middle -> [1/3, 2/3]   >    the three THIRDS
 *         bottom -> [2/3, 1  ]  /
 *         upper  -> [0,   1/2]  \     the two HALVES
 *         lower  -> [1/2, 1  ]  /
 *
 *         y      =           round(fracTop    * H + marginY)  + offsetY
 *         height = max(0,    round(fracBottom * H - marginY)
 *                          - round(fracTop    * H + marginY))
 *
 *     HORIZONTAL — 12 CONTIGUOUS columns inside the horizontal margins
 *
 *         contentWidth = max(0, W - 2 * marginX)
 *         columnWidth  = contentWidth / 12
 *         edge(k)      = round(marginX + k * columnWidth)      k = 0 … 12
 *
 *         x      = edge(colStart - 1) + offsetX
 *         width  = edge(colEnd) - edge(colStart - 1)
 *
 * Identities that fall out of it and that callers may rely on:
 *
 * - `col: [1, 12]`, `marginX: 0` is **exactly** `x = 0, width = W`.
 * - `[a, b]` and `[b + 1, c]` tile with no gap and no overlap at any `W`,
 *   because they share the single rounded `edge(b)`.
 * - `top` + `middle` + `bottom` sum to exactly `H`; so do `upper` + `lower`.
 *
 * ## Why thirds AND halves
 *
 * They answer different questions and neither is derivable from the other
 * without arithmetic the DSL exists to remove. *"Headline in the top third"*
 * (a poster's title zone) and *"photo in the upper half"* (a split layout) are
 * both things an agent wants to say directly. The six names split cleanly and
 * unambiguously: `top`/`middle`/`bottom` read as a triple — `middle` only
 * means anything as the middle of three — and `upper`/`lower` read as a pair.
 * With both words present nobody reads `upper` as a third or `top` as a half.
 *
 * ## Why the columns are contiguous (no gutter)
 *
 * A print grid usually has gutters; this one deliberately does not. There is
 * no gutter parameter in the DSL, so a gutter would be a number invented here
 * — and it would break the two properties an agent relies on most: `col:
 * [1, 12]` with no margin would stop being the full canvas width, and
 * `[1, 6]` / `[7, 12]` would stop tiling. Spacing between neighbours is
 * already expressible with the existing vocabulary — skip a column
 * (`[1, 5]` next to `[7, 12]`) or use `marginX` — so nothing is lost.
 *
 * ## Why edges are rounded, and widths derived from them
 *
 * Canvas coordinates are floats, but an agent that asks for half of a 1080
 * canvas should get 540, not 539.9999999999999. So grid-derived EDGES are
 * rounded to whole pixels — and the width is then the DIFFERENCE OF TWO
 * ROUNDED EDGES, never a rounded width. That distinction is the whole point:
 * on A4 (`W = 2480`, `columnWidth = 206.66…`) rounding widths independently
 * would make `[1, 4]`, `[5, 8]` and `[9, 12]` sum to 2481 and leave a 1 px
 * seam in the middle; sharing rounded edges makes them sum to exactly 2480 by
 * construction. The authored `offsetX` / `offsetY` are applied AFTER the
 * rounding, un-rounded: they translate the rect and must not change its size,
 * and an agent that writes a fractional offset asked for it.
 *
 * ## What the height MEANS (the subtle one)
 *
 * The resolved rect always carries the band height. {@see Rect::$height} being
 * nullable is a statement about what the AUTHOR wrote, not about what the grid
 * knows — and the band height is real information only this class can compute,
 * so withholding it would just force the compiler to re-derive the area math
 * and the two copies would drift.
 *
 * It is **a bound offered, never a size imposed.** Deciding whether an element
 * may have a height at all belongs to the compiler (S4-T4):
 *
 * - a **textbox must not** (plan §4.2-6: Fabric computes its height from the
 *   wrapped text; authoring one is a rendering bug), so the compiler drops it
 *   — the parser already refuses an authored `height` on a text element;
 * - an **image** with no authored `height` takes the band height, which is
 *   what makes `{"area": "full"}` a full-bleed picture and `{"area":
 *   "bottom"}` exactly the bottom third with no arithmetic;
 * - an authored `height` wins over the band, via {@see Placement::resolve()}.
 *
 * ## Anchoring is uniformly top-left, and nothing is clamped
 *
 * Every area anchors its rect at the band's TOP edge. The tempting
 * alternative — bottom-anchoring `bottom` so an authored height ends flush
 * with the canvas — is rejected: it would make `y` a function of the authored
 * `height`, which lives on {@see Placement} and not on
 * {@see SemanticPlacement}, collapsing the split this class exists to
 * preserve; it would need a per-area anchoring rule the DSL has no words for;
 * and it would make the decompiler's inversion ambiguous. An element that must
 * hug the canvas bottom says so with a negative `offsetY` or absolute `y`.
 *
 * For the same reason nothing is clamped to the canvas. The plan's own image
 * example (`{"area": "bottom"}` with `height: 480`) resolves to `y = 720 …
 * 1200` on a 1080 canvas, and the linter (S4-T6) reports the 120 px that fall
 * off. Clamping would hide the mistake instead of naming it, and would make
 * the forward math non-invertible.
 *
 * ## ⚠️ `at.row` is deliberately NOT in v1
 *
 * Vertical placement is `area` + `offsetY`. A row count would be a second,
 * overlapping vertical vocabulary frozen into the contract before anything
 * consumes it. Adding it later costs one property on
 * {@see SemanticPlacement}, one entry in `DslParser::AT_KEYS` and one term in
 * the vertical formula above.
 *
 * ## Why static, and not a service
 *
 * Same reasoning as `DslParser`, and one reason more. It is a pure function of
 * `(SemanticPlacement, CanvasSpec)`: no dependencies, no state outliving a
 * call, no project context. A service would give it a lifecycle it does not
 * need and an injection point — and here that injection point is actively
 * harmful, because a swappable grid is not a contract. `src/Mcp/Design/
 * Geometry/` is therefore excluded from the container next to `Design/Dsl/`.
 *
 * @see Placement::resolve() the "absolute wins" merge — NOT re-implemented here
 */
final class GridResolver
{
    private function __construct()
    {
    }

    /**
     * The grid rect for a semantic placement, or null when there is none.
     *
     * Null is the honest answer for `$at === null`, not an error: it is
     * exactly what {@see Placement::resolve()} expects for a fully absolute
     * placement, and the parser already guarantees that such a placement
     * carries `x`, `y` and `width`. Refusing here would be a second gate on
     * the same rule — and would stop the linter and the decompiler, which
     * legitimately hold half-built placements, from calling this at all.
     *
     * The returned height is never null: a band always has one. See the class
     * docblock for what it means.
     */
    public static function resolve(null|SemanticPlacement $at, CanvasSpec $canvas): null|Rect
    {
        if ($at === null) {
            return null;
        }

        [$fractionTop, $fractionBottom] = self::areaFractions($at->area);

        $top = round($fractionTop * $canvas->height + $at->marginY);
        $bottom = round($fractionBottom * $canvas->height - $at->marginY);

        $left = self::columnEdge($at->colStart - 1, $canvas->width, $at->marginX);
        $right = self::columnEdge($at->colEnd, $canvas->width, $at->marginX);

        return new Rect(
            $left + $at->offsetX,
            $top + $at->offsetY,
            $right - $left,
            // A marginY wide enough to swallow its own band yields 0, never a
            // negative height; the linter reports the degenerate rect. This
            // class does not invent space it was not given.
            max(0.0, $bottom - $top),
        );
    }

    /**
     * The final rect for an element: grid math, then the authored absolutes on
     * top. **This is the call site consumers want** — it is the only way to
     * get both halves without one of them re-deriving the other's rule.
     */
    public static function resolvePlacement(Placement $placement, CanvasSpec $canvas): Rect
    {
        return $placement->resolve(self::resolve($placement->at, $canvas));
    }

    /**
     * The band's `[top, bottom]` fraction of the canvas height — the literal
     * table from the class docblock, exposed so the decompiler and the Skill
     * read the contract instead of restating it.
     *
     * @return array{float, float}
     */
    public static function areaFractions(PlacementArea $area): array
    {
        return match ($area) {
            PlacementArea::Full => [0.0, 1.0],
            PlacementArea::Top => [0.0, 1.0 / 3.0],
            PlacementArea::Middle => [1.0 / 3.0, 2.0 / 3.0],
            PlacementArea::Bottom => [2.0 / 3.0, 1.0],
            PlacementArea::Upper => [0.0, 0.5],
            PlacementArea::Lower => [0.5, 1.0],
        };
    }

    /**
     * The band's `[top, bottom]` edge in canvas pixels, un-inset (`marginY`
     * and `offsetY` are the caller's to apply — see the vertical formula).
     *
     * Takes a plain height rather than a {@see CanvasSpec} so a caller holding
     * a `TemplateDimension` (the decompiler) need not fabricate one.
     *
     * @return array{float, float}
     */
    public static function areaBand(PlacementArea $area, int $canvasHeight): array
    {
        [$fractionTop, $fractionBottom] = self::areaFractions($area);

        return [round($fractionTop * $canvasHeight), round($fractionBottom * $canvasHeight)];
    }

    /**
     * `edge(k)` — the x of the k-th vertical grid line, `k = 0 … 12`, rounded
     * to a whole pixel. `edge(0)` is the content box's left edge and
     * `edge(12)` its right edge.
     *
     * Out-of-range indexes are not rejected: the arithmetic extrapolates
     * cleanly and the column range is the parser's business, not the grid's.
     */
    public static function columnEdge(int $edgeIndex, int $canvasWidth, float $marginX = 0.0): float
    {
        $contentWidth = max(0.0, $canvasWidth - 2 * $marginX);
        $columnWidth = $contentWidth / SemanticPlacement::GRID_COLUMNS;

        return round($marginX + $edgeIndex * $columnWidth);
    }

    /**
     * All 13 grid lines, left to right — the whole horizontal contract in one
     * array. Consecutive entries are the column boundaries, so any two
     * adjacent spans provably share an edge.
     *
     * @return non-empty-list<float>
     */
    public static function columnEdges(int $canvasWidth, float $marginX = 0.0): array
    {
        $edges = [];

        for ($index = 0; $index <= SemanticPlacement::GRID_COLUMNS; $index++) {
            $edges[] = self::columnEdge($index, $canvasWidth, $marginX);
        }

        return $edges;
    }
}
