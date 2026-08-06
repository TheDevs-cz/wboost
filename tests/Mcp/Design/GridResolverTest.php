<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Design;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\InvalidDesignDocument;
use WBoost\Web\Mcp\Design\Dsl\CanvasSpec;
use WBoost\Web\Mcp\Design\Dsl\DslParser;
use WBoost\Web\Mcp\Design\Dsl\Placement;
use WBoost\Web\Mcp\Design\Dsl\PlacementArea;
use WBoost\Web\Mcp\Design\Dsl\Rect;
use WBoost\Web\Mcp\Design\Dsl\SemanticPlacement;
use WBoost\Web\Mcp\Design\Geometry\GridResolver;

/**
 * The grid geometry (S4-T2), pinned — no kernel, no container, no fonts. It is
 * arithmetic, and it is a **public contract**: an authoring agent writes
 * against these numbers, the Skill documents them and the decompiler (S4-T5)
 * has to invert them, so a change that moves a pixel must fail here loudly.
 *
 * Two kinds of assertion live below and they earn their place differently:
 *
 * 1. **Pinned tables** — every {@see PlacementArea} on a 1080 x 1080 square
 *    and on A4 @ 300 DPI (2480 x 3508), written out as literal pixels. A4 is
 *    the interesting canvas precisely because neither thirds nor twelfths
 *    divide it evenly; the square would let every rounding bug through.
 * 2. **Properties** — full span == the whole canvas, adjacent spans tile with
 *    no seam, thirds and halves sum to the canvas height. These are what
 *    catch a gutter creeping in or a width being rounded independently of its
 *    edges, which no table of individual values would notice.
 *
 * The area tables carry a coverage guard, so adding a seventh area fails here
 * rather than shipping with an unpinned meaning.
 */
final class GridResolverTest extends TestCase
{
    private const int SQUARE = 1080;
    private const int A4_WIDTH = 2480;
    private const int A4_HEIGHT = 3508;

    /**
     * The 13 grid lines of an un-inset canvas. 1080 divides evenly; 2480 does
     * not (206.66… per column), which is what makes the shared-edge rule
     * observable.
     *
     * @var list<float>
     */
    private const array SQUARE_EDGES = [0.0, 90.0, 180.0, 270.0, 360.0, 450.0, 540.0, 630.0, 720.0, 810.0, 900.0, 990.0, 1080.0];

    /** @var list<float> */
    private const array A4_EDGES = [0.0, 207.0, 413.0, 620.0, 827.0, 1033.0, 1240.0, 1447.0, 1653.0, 1860.0, 2067.0, 2273.0, 2480.0];

    // -----------------------------------------------------------------
    // the pinned tables — every area, both canvases
    // -----------------------------------------------------------------

    /**
     * @param array{float, float} $expected [y, height] of the full-width band
     */
    #[DataProvider('squareAreas')]
    public function testResolvesEveryAreaOnASquareCanvas(PlacementArea $area, array $expected): void
    {
        $rect = self::resolve(new SemanticPlacement($area), self::square());

        self::assertRect(new Rect(0.0, $expected[0], 1080.0, $expected[1]), $rect);
    }

    /**
     * @return iterable<string, array{PlacementArea, array{float, float}}>
     */
    public static function squareAreas(): iterable
    {
        foreach (self::squareAreaTable() as $name => $band) {
            yield $name => [PlacementArea::from($name), $band];
        }
    }

    /**
     * @param array{float, float} $expected [y, height] of the full-width band
     */
    #[DataProvider('a4Areas')]
    public function testResolvesEveryAreaOnAnA4Canvas(PlacementArea $area, array $expected): void
    {
        $rect = self::resolve(new SemanticPlacement($area), self::a4());

        self::assertRect(new Rect(0.0, $expected[0], 2480.0, $expected[1]), $rect);
    }

    /**
     * @return iterable<string, array{PlacementArea, array{float, float}}>
     */
    public static function a4Areas(): iterable
    {
        foreach (self::a4AreaTable() as $name => $band) {
            yield $name => [PlacementArea::from($name), $band];
        }
    }

    /**
     * Adding an area without pinning it is the failure mode this guards: the
     * data providers above would simply not mention it and both tables would
     * stay green while the new band's geometry went out undocumented.
     */
    public function testTheAreaTablesCoverEveryPlacementArea(): void
    {
        $cases = array_map(static fn (PlacementArea $area): string => $area->value, PlacementArea::cases());

        sort($cases);

        $square = array_keys(self::squareAreaTable());
        $a4 = array_keys(self::a4AreaTable());
        sort($square);
        sort($a4);

        self::assertSame($cases, $square, 'Every PlacementArea must be pinned on the square canvas.');
        self::assertSame($cases, $a4, 'Every PlacementArea must be pinned on the A4 canvas.');
    }

    // -----------------------------------------------------------------
    // vertical properties — thirds and halves tile exactly
    // -----------------------------------------------------------------

    public function testTheThreeThirdsTileTheCanvasHeightWithNoGapAndNoOverlap(): void
    {
        foreach ([self::square(), self::a4()] as $canvas) {
            $top = self::resolve(new SemanticPlacement(PlacementArea::Top), $canvas);
            $middle = self::resolve(new SemanticPlacement(PlacementArea::Middle), $canvas);
            $bottom = self::resolve(new SemanticPlacement(PlacementArea::Bottom), $canvas);

            self::assertSame(0.0, $top->y);
            self::assertSame($top->bottom(), $middle->y, 'middle must start exactly where top ends');
            self::assertSame($middle->bottom(), $bottom->y, 'bottom must start exactly where middle ends');
            self::assertSame((float) $canvas->height, $bottom->bottom());
            self::assertSame(
                (float) $canvas->height,
                self::heightOf($top) + self::heightOf($middle) + self::heightOf($bottom),
                'the three thirds must sum to the canvas height, rounding included',
            );
        }
    }

    public function testTheTwoHalvesTileTheCanvasHeightWithNoGapAndNoOverlap(): void
    {
        foreach ([self::square(), self::a4()] as $canvas) {
            $upper = self::resolve(new SemanticPlacement(PlacementArea::Upper), $canvas);
            $lower = self::resolve(new SemanticPlacement(PlacementArea::Lower), $canvas);

            self::assertSame(0.0, $upper->y);
            self::assertSame($upper->bottom(), $lower->y);
            self::assertSame((float) $canvas->height, $lower->bottom());
            self::assertSame((float) $canvas->height, self::heightOf($upper) + self::heightOf($lower));
        }
    }

    public function testFullIsTheWholeCanvas(): void
    {
        foreach ([self::square(), self::a4()] as $canvas) {
            $rect = self::resolve(new SemanticPlacement(PlacementArea::Full), $canvas);

            self::assertRect(
                new Rect(0.0, 0.0, (float) $canvas->width, (float) $canvas->height),
                $rect,
            );
        }
    }

    // -----------------------------------------------------------------
    // horizontal properties — the column grid
    // -----------------------------------------------------------------

    public function testFullColumnSpanWithNoMarginIsExactlyTheCanvasWidth(): void
    {
        foreach ([self::square(), self::a4()] as $canvas) {
            $rect = self::resolve(
                new SemanticPlacement(PlacementArea::Full, 1, SemanticPlacement::GRID_COLUMNS),
                $canvas,
            );

            self::assertSame(0.0, $rect->x);
            self::assertSame((float) $canvas->width, $rect->width, 'col [1, 12] with marginX 0 must be the full width');
        }
    }

    public function testASingleColumnIsOneColumnWideNotZero(): void
    {
        // The 1-based inclusive off-by-one: [1, 1] spans edge(0)..edge(1).
        $square = self::resolve(new SemanticPlacement(PlacementArea::Full, 1, 1), self::square());
        self::assertSame(0.0, $square->x);
        self::assertSame(90.0, $square->width);

        $a4 = self::resolve(new SemanticPlacement(PlacementArea::Full, 1, 1), self::a4());
        self::assertSame(0.0, $a4->x);
        self::assertSame(207.0, $a4->width);

        // …and the last column is the last slice, not an empty one past the edge.
        $last = self::resolve(new SemanticPlacement(PlacementArea::Full, 12, 12), self::a4());
        self::assertSame(2273.0, $last->x);
        self::assertSame(207.0, $last->width);
    }

    public function testHalfWidthColumnSpansTileTheCanvasWithNoGapAndNoOverlap(): void
    {
        foreach ([self::square(), self::a4()] as $canvas) {
            $left = self::resolve(new SemanticPlacement(PlacementArea::Full, 1, 6), $canvas);
            $right = self::resolve(new SemanticPlacement(PlacementArea::Full, 7, 12), $canvas);

            self::assertSame(0.0, $left->x);
            self::assertSame($left->right(), $right->x, 'the right column must start exactly where the left one ends');
            self::assertSame((float) $canvas->width, $right->right());
            self::assertSame(
                (float) $canvas->width,
                $left->width + $right->width,
                'two halves of the grid must sum to the canvas width EXACTLY - a gutter or an independently rounded width shows up here',
            );
        }
    }

    /**
     * The case that a table of individual values would miss: on A4 a column is
     * 206.66… px, so rounding each WIDTH would give 827 + 827 + 827 = 2481 and
     * leave a 1 px seam. Deriving widths from shared rounded EDGES gives
     * 827 + 826 + 827 = 2480.
     */
    public function testThreeQuarterWidthColumnSpansAlsoTileACanvasThatDoesNotDivideEvenly(): void
    {
        $canvas = self::a4();

        $first = self::resolve(new SemanticPlacement(PlacementArea::Full, 1, 4), $canvas);
        $second = self::resolve(new SemanticPlacement(PlacementArea::Full, 5, 8), $canvas);
        $third = self::resolve(new SemanticPlacement(PlacementArea::Full, 9, 12), $canvas);

        self::assertSame([0.0, 827.0], [$first->x, $first->width]);
        self::assertSame([827.0, 826.0], [$second->x, $second->width]);
        self::assertSame([1653.0, 827.0], [$third->x, $third->width]);
        self::assertSame(2480.0, $first->width + $second->width + $third->width);
    }

    public function testEverySplitPointTilesTheWidth(): void
    {
        foreach ([self::square(), self::a4()] as $canvas) {
            for ($split = 1; $split < SemanticPlacement::GRID_COLUMNS; $split++) {
                $left = self::resolve(new SemanticPlacement(PlacementArea::Full, 1, $split), $canvas);
                $right = self::resolve(new SemanticPlacement(PlacementArea::Full, $split + 1, 12), $canvas);

                self::assertSame(
                    (float) $canvas->width,
                    $left->width + $right->width,
                    sprintf('split after column %d must lose no pixel on a %d px canvas', $split, $canvas->width),
                );
                self::assertSame($left->right(), $right->x);
            }
        }
    }

    public function testColumnEdgesAreTheDocumentedThirteenWholePixelGridLines(): void
    {
        self::assertSame(self::SQUARE_EDGES, self::floats(GridResolver::columnEdges(self::SQUARE)));
        self::assertSame(self::A4_EDGES, self::floats(GridResolver::columnEdges(self::A4_WIDTH)));
    }

    public function testColumnEdgesAreInsetByTheHorizontalMargin(): void
    {
        self::assertSame(
            self::floats([80, 157, 233, 310, 387, 463, 540, 617, 693, 770, 847, 923, 1000]),
            self::floats(GridResolver::columnEdges(self::SQUARE, 80.0)),
        );
    }

    // -----------------------------------------------------------------
    // the inverses the decompiler (S4-T5) reads the contract through
    // -----------------------------------------------------------------

    public function testAreaFractionsAreTheDocumentedThirdsAndHalves(): void
    {
        self::assertSame([0.0, 1.0], GridResolver::areaFractions(PlacementArea::Full));
        self::assertSame([0.0, 1.0 / 3.0], GridResolver::areaFractions(PlacementArea::Top));
        self::assertSame([1.0 / 3.0, 2.0 / 3.0], GridResolver::areaFractions(PlacementArea::Middle));
        self::assertSame([2.0 / 3.0, 1.0], GridResolver::areaFractions(PlacementArea::Bottom));
        self::assertSame([0.0, 0.5], GridResolver::areaFractions(PlacementArea::Upper));
        self::assertSame([0.5, 1.0], GridResolver::areaFractions(PlacementArea::Lower));
    }

    /**
     * `areaBand()` and `resolve()` are two public entry points onto one
     * formula; the only thing stopping them drifting is that they agree here,
     * for every area, on both canvases.
     */
    public function testAreaBandAgreesWithTheUnInsetVerticalHalfOfResolve(): void
    {
        foreach ([self::square(), self::a4()] as $canvas) {
            foreach (PlacementArea::cases() as $area) {
                $rect = self::resolve(new SemanticPlacement($area), $canvas);

                self::assertSame(
                    [$rect->y, $rect->bottom()],
                    GridResolver::areaBand($area, $canvas->height),
                    sprintf('area "%s" on a %d px tall canvas', $area->value, $canvas->height),
                );
            }
        }
    }

    /**
     * Same argument for the horizontal half: `columnEdges()` must be the
     * boundaries `resolve()` actually lands on, not a parallel derivation.
     */
    public function testColumnEdgesAgreeWithTheSpansResolveProduces(): void
    {
        foreach ([self::square(), self::a4()] as $canvas) {
            $edges = GridResolver::columnEdges($canvas->width);

            for ($column = 1; $column <= SemanticPlacement::GRID_COLUMNS; $column++) {
                $rect = self::resolve(new SemanticPlacement(PlacementArea::Full, $column, $column), $canvas);

                self::assertSame($edges[$column - 1], $rect->x);
                self::assertSame($edges[$column], $rect->right());
            }
        }
    }

    // -----------------------------------------------------------------
    // margins and offsets — applied in the right order and direction
    // -----------------------------------------------------------------

    public function testMarginXInsetsBothSidesAndShrinksEveryColumn(): void
    {
        $rect = self::resolve(
            new SemanticPlacement(PlacementArea::Full, 1, 12, marginX: 80.0),
            self::square(),
        );

        self::assertSame(80.0, $rect->x, 'the left inset is the margin itself');
        self::assertSame(920.0, $rect->width, '1080 - 2 x 80: the margin is taken off BOTH sides');
        self::assertSame(1000.0, $rect->right());
    }

    public function testMarginYInsetsBothEndsOfTheBand(): void
    {
        $rect = self::resolve(
            new SemanticPlacement(PlacementArea::Top, marginY: 40.0),
            self::square(),
        );

        self::assertSame(40.0, $rect->y, 'the band starts one margin down');
        self::assertSame(280.0, $rect->height, '360 - 2 x 40: the margin is taken off BOTH ends');
    }

    public function testOffsetsTranslateTheRectWithoutResizingIt(): void
    {
        $plain = self::resolve(new SemanticPlacement(PlacementArea::Middle, 2, 5), self::a4());
        $nudged = self::resolve(
            new SemanticPlacement(PlacementArea::Middle, 2, 5, offsetX: -12.0, offsetY: 34.0),
            self::a4(),
        );

        self::assertSame($plain->x - 12.0, $nudged->x);
        self::assertSame($plain->y + 34.0, $nudged->y);
        self::assertSame($plain->width, $nudged->width, 'an offset must never change the size');
        self::assertSame($plain->height, $nudged->height);
    }

    /**
     * Order matters and is observable: the margin insets the GRID (so it also
     * shrinks the width), the offset translates the RESULT (so it does not).
     */
    public function testTheMarginInsetsTheGridAndTheOffsetThenTranslatesTheResult(): void
    {
        $rect = self::resolve(
            new SemanticPlacement(PlacementArea::Top, 1, 12, marginX: 80.0, marginY: 40.0, offsetX: -20.0, offsetY: 25.0),
            self::square(),
        );

        self::assertRect(new Rect(60.0, 65.0, 920.0, 280.0), $rect);
    }

    /**
     * The plan's own headline example, resolved. Keeping it here means the
     * documented sample cannot quietly stop meaning what it says.
     */
    public function testThePlanHeadlineExampleResolvesAsDocumented(): void
    {
        $rect = self::resolve(
            new SemanticPlacement(PlacementArea::Top, 1, 12, marginX: 80.0, offsetY: 40.0),
            self::square(),
        );

        self::assertRect(new Rect(80.0, 40.0, 920.0, 360.0), $rect);
    }

    public function testAMarginThatSwallowsItsBandYieldsAZeroHeightNotANegativeOne(): void
    {
        $rect = self::resolve(
            new SemanticPlacement(PlacementArea::Top, marginY: 200.0),
            self::square(),
        );

        self::assertSame(200.0, $rect->y);
        self::assertSame(0.0, $rect->height, 'the resolver refuses to invent space it was not given');
    }

    public function testAMarginWiderThanTheCanvasYieldsAZeroWidthNotANegativeOne(): void
    {
        $rect = self::resolve(
            new SemanticPlacement(PlacementArea::Full, 1, 12, marginX: 600.0),
            self::square(),
        );

        self::assertSame(0.0, $rect->width);
    }

    // -----------------------------------------------------------------
    // the height contract
    // -----------------------------------------------------------------

    /**
     * A semantic placement ALWAYS resolves with a height — the band's. That is
     * deliberate even for a text, whose Rect::$height would otherwise be null:
     * withholding it would force the compiler to re-derive the area math, and
     * two copies of a contract drift. Dropping it for a textbox (plan §4.2-6)
     * is the compiler's call, not the grid's.
     */
    public function testTheGridRectAlwaysCarriesTheBandHeightEvenForATextPlacement(): void
    {
        $text = new Placement(new SemanticPlacement(PlacementArea::Upper));

        $grid = GridResolver::resolve($text->at, self::square());
        self::assertInstanceOf(Rect::class, $grid);
        self::assertSame(540.0, $grid->height);

        // …and it survives the absolute merge untouched, because a text may
        // not author a height in the first place.
        self::assertSame(540.0, GridResolver::resolvePlacement($text, self::square())->height);
    }

    // -----------------------------------------------------------------
    // absolute wins — the split with Placement::resolve()
    // -----------------------------------------------------------------

    public function testAbsoluteCoordinatesOverrideTheGridPerProperty(): void
    {
        $placement = new Placement(
            new SemanticPlacement(PlacementArea::Top, 1, 12, marginX: 80.0),
            x: 12.0,
            width: 300.0,
        );

        $rect = GridResolver::resolvePlacement($placement, self::square());

        self::assertSame(12.0, $rect->x, 'the authored x wins');
        self::assertSame(300.0, $rect->width, 'the authored width wins');
        self::assertSame(0.0, $rect->y, 'y was not authored, so the band still supplies it');
        self::assertSame(360.0, $rect->height);
    }

    /**
     * The plan's image example: `{"area": "bottom"}` with an authored height.
     * The height wins over the band's 360 and the result runs 120 px off a
     * 1080 canvas — NOT clamped. The linter (S4-T6) is what reports that;
     * clamping here would hide the mistake and break the decompiler's
     * inversion.
     */
    public function testAnAuthoredHeightWinsOverTheBandAndIsNotClampedToTheCanvas(): void
    {
        $placement = new Placement(new SemanticPlacement(PlacementArea::Bottom), height: 480.0);

        $rect = GridResolver::resolvePlacement($placement, self::square());

        self::assertRect(new Rect(0.0, 720.0, 1080.0, 480.0), $rect);
        self::assertSame(1200.0, $rect->bottom());
    }

    public function testAFullyAbsolutePlacementNeedsNoGridRectAtAll(): void
    {
        $placement = new Placement(x: 40.0, y: 50.0, width: 600.0);

        self::assertNull(GridResolver::resolve($placement->at, self::square()));
        self::assertRect(new Rect(40.0, 50.0, 600.0, null), GridResolver::resolvePlacement($placement, self::square()));
    }

    // -----------------------------------------------------------------
    // neither `at` nor absolutes
    // -----------------------------------------------------------------

    /**
     * The parser is the ONLY gate on this: a placement with neither `at` nor
     * x/y/width is refused at parse time with a path an agent can act on. The
     * resolver deliberately does not re-check it — a second refusal of the
     * same rule is exactly the drift the DSL layering avoids, and a throwing
     * resolver could not be called by the linter or the decompiler, which
     * legitimately hold half-built placements.
     */
    public function testAPlacementWithNeitherSemanticNorAbsoluteCoordinatesIsRefusedByTheParser(): void
    {
        try {
            DslParser::parse([
                'canvas' => ['width' => 1080, 'height' => 1080],
                'elements' => [[
                    'kind' => 'text',
                    'id' => 'headline',
                    'text' => 'Hi',
                    'font' => 'Hero New (Hero New ExtraBold)',
                    'size' => 96,
                ]],
            ]);
        } catch (InvalidDesignDocument $exception) {
            self::assertSame('elements[0]', $exception->violations[0]->path);
            self::assertStringContainsString('no resolvable placement', $exception->violations[0]->message);

            return;
        }

        self::fail('A placement with neither "at" nor x/y/width must not parse.');
    }

    /**
     * …and if one is hand-built anyway (bypassing the parser), the merge stays
     * TOTAL rather than fatal: the origin rect, no height. Pinned so the
     * fallback is a decision, not an accident.
     */
    public function testAHandBuiltEmptyPlacementDegradesToTheOriginRect(): void
    {
        self::assertRect(
            new Rect(0.0, 0.0, 0.0, null),
            GridResolver::resolvePlacement(new Placement(), self::square()),
        );
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{float, float}> area value => [y, height] at full width
     */
    private static function squareAreaTable(): array
    {
        return [
            'full' => [0.0, 1080.0],
            'top' => [0.0, 360.0],
            'upper' => [0.0, 540.0],
            'middle' => [360.0, 360.0],
            'lower' => [540.0, 540.0],
            'bottom' => [720.0, 360.0],
        ];
    }

    /**
     * A4 @ 300 DPI. 3508 / 3 = 1169.33…, so the middle third is the one that
     * absorbs the rounding remainder (1170) — pinned on purpose.
     *
     * @return array<string, array{float, float}> area value => [y, height] at full width
     */
    private static function a4AreaTable(): array
    {
        return [
            'full' => [0.0, 3508.0],
            'top' => [0.0, 1169.0],
            'upper' => [0.0, 1754.0],
            'middle' => [1169.0, 1170.0],
            'lower' => [1754.0, 1754.0],
            'bottom' => [2339.0, 1169.0],
        ];
    }

    private static function square(): CanvasSpec
    {
        return new CanvasSpec(self::SQUARE, self::SQUARE);
    }

    private static function a4(): CanvasSpec
    {
        return new CanvasSpec(self::A4_WIDTH, self::A4_HEIGHT);
    }

    /**
     * The grid half. Asserts the standing guarantee — a semantic placement
     * ALWAYS resolves, and always with a band height — once, here, instead of
     * in every case below.
     */
    private static function resolve(SemanticPlacement $at, CanvasSpec $canvas): Rect
    {
        $rect = GridResolver::resolve($at, $canvas);

        self::assertInstanceOf(Rect::class, $rect);
        self::assertNotNull($rect->height, 'a semantic placement always resolves to a band height');

        return $rect;
    }

    private static function heightOf(Rect $rect): float
    {
        $height = $rect->height;

        self::assertNotNull($height);

        return $height;
    }

    private static function assertRect(Rect $expected, Rect $actual): void
    {
        self::assertSame(
            [$expected->x, $expected->y, $expected->width, $expected->height],
            [$actual->x, $actual->y, $actual->width, $actual->height],
            'x / y / width / height',
        );
    }

    /**
     * @param list<float|int> $values
     * @return list<float>
     */
    private static function floats(array $values): array
    {
        return array_map(static fn (float|int $value): float => (float) $value, $values);
    }
}
