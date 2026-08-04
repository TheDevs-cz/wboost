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
