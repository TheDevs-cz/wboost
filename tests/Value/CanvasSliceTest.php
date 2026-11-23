<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\CanvasSlice;

/**
 * The gap derivation feeding the fill page's layered preview: which z-ranges
 * of the object stack must render as transparent overlays above each image
 * placeholder so the designed stacking order survives the hybrid preview.
 *
 * @covers \WBoost\Web\Value\CanvasSlice
 */
final class CanvasSliceTest extends TestCase
{
    public function testContentAboveSinglePlaceholderYieldsOneOpenEndedGap(): void
    {
        $objects = [
            ['type' => 'image'],                              // 0: decorative, below
            ['type' => 'image', 'imagePlaceholder' => true],  // 1: the slot
            ['type' => 'image'],                              // 2: locked image ABOVE the slot
            ['type' => 'textbox'],                            // 3: text above too
        ];

        $gaps = CanvasSlice::overlayGapsAbovePlaceholders($objects, ['slot-a' => 1]);

        self::assertCount(1, $gaps);
        self::assertSame('slot-a', $gaps[0]['aboveInputId']);
        self::assertSame(2, $gaps[0]['slice']->fromIndex);
        self::assertNull($gaps[0]['slice']->toIndex);
        self::assertFalse($gaps[0]['slice']->withBackground);
    }

    /**
     * An object with a Režim prolnutí becomes a part of its own, tagged with
     * its mode, so the fill canvas can blend that slice over the client's
     * picture; the normal runs around it keep sharing one part each.
     */
    public function testABlendedObjectSplitsTheGapIntoItsOwnTaggedPart(): void
    {
        $objects = [
            ['type' => 'image', 'imagePlaceholder' => true],                     // 0: the slot
            ['type' => 'textbox'],                                               // 1: normal
            ['type' => 'Rect', 'globalCompositeOperation' => 'source-over'],     // 2: explicit normal
            ['type' => 'Rect', 'globalCompositeOperation' => 'multiply'],        // 3: blended
            ['type' => 'Rect', 'globalCompositeOperation' => 'screen', 'visible' => false], // 4: hidden
            ['type' => 'textbox'],                                               // 5: normal
            ['type' => 'image', 'imagePlaceholder' => true],                     // 6: second slot
            ['type' => 'Rect', 'globalCompositeOperation' => 'soft-light'],      // 7: blended, top
        ];

        $gaps = CanvasSlice::overlayGapsAbovePlaceholders($objects, ['a' => 0, 'b' => 6]);

        $summary = array_map(static fn (array $gap): array => [
            $gap['aboveInputId'],
            $gap['part'],
            $gap['slice']->fromIndex,
            $gap['slice']->toIndex,
            $gap['blendMode']->value,
        ], $gaps);

        self::assertSame([
            ['a', 0, 1, 3, 'source-over'],
            ['a', 1, 3, 4, 'multiply'],
            ['a', 2, 4, 6, 'source-over'],
            ['b', 0, 7, null, 'soft-light'],
        ], $summary);
    }

    public function testNothingAbovePlaceholdersYieldsNoGaps(): void
    {
        $objects = [
            ['type' => 'textbox'],
            ['type' => 'image', 'imagePlaceholder' => true],
        ];

        self::assertSame([], CanvasSlice::overlayGapsAbovePlaceholders($objects, ['slot-a' => 1]));
    }

    public function testGapHoldingOnlyDesignerHiddenObjectsIsSkipped(): void
    {
        $objects = [
            ['type' => 'image', 'imagePlaceholder' => true],
            ['type' => 'image', 'visible' => false],
        ];

        self::assertSame([], CanvasSlice::overlayGapsAbovePlaceholders($objects, ['slot-a' => 0]));
    }

    public function testInterleavedPlaceholdersSliceAtEachGap(): void
    {
        $objects = [
            ['type' => 'image', 'imagePlaceholder' => true],  // 0: lower slot
            ['type' => 'image'],                              // 1: divider between the slots
            ['type' => 'image', 'imagePlaceholder' => true],  // 2: upper slot
            ['type' => 'textbox'],                            // 3: title above everything
        ];

        $gaps = CanvasSlice::overlayGapsAbovePlaceholders($objects, ['upper' => 2, 'lower' => 0]);

        self::assertCount(2, $gaps);

        // Bottom-up regardless of the input map's order.
        self::assertSame('lower', $gaps[0]['aboveInputId']);
        self::assertSame(1, $gaps[0]['slice']->fromIndex);
        self::assertSame(2, $gaps[0]['slice']->toIndex);

        self::assertSame('upper', $gaps[1]['aboveInputId']);
        self::assertSame(3, $gaps[1]['slice']->fromIndex);
        self::assertNull($gaps[1]['slice']->toIndex);
    }

    public function testAdjacentPlaceholdersProduceNoEmptyGapBetweenThem(): void
    {
        $objects = [
            ['type' => 'image', 'imagePlaceholder' => true],
            ['type' => 'image', 'imagePlaceholder' => true],
            ['type' => 'image'],
        ];

        $gaps = CanvasSlice::overlayGapsAbovePlaceholders($objects, ['a' => 0, 'b' => 1]);

        self::assertCount(1, $gaps);
        self::assertSame('b', $gaps[0]['aboveInputId']);
        self::assertSame(2, $gaps[0]['slice']->fromIndex);
    }

    public function testNoPlaceholdersYieldsNoGaps(): void
    {
        self::assertSame([], CanvasSlice::overlayGapsAbovePlaceholders([['type' => 'image']], []));
    }
}
