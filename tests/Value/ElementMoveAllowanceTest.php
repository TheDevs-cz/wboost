<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\ElementMoveAllowance;
use WBoost\Web\Value\ElementOffsets;

/**
 * "Uživatel může posunout" core: normalisation of the canvas `userMove`
 * contract, per-direction bounds (unlimited = stays on the artwork), clamping,
 * group scaling and the offset bake.
 *
 * @covers \WBoost\Web\Value\ElementMoveAllowance
 * @covers \WBoost\Web\Value\ElementOffsets
 */
final class ElementMoveAllowanceTest extends TestCase
{
    private const array FRAME = ['left' => 100.0, 'top' => 200.0, 'width' => 300.0, 'height' => 50.0];

    public function testNothingAllowedIsNotMovable(): void
    {
        self::assertNull(ElementMoveAllowance::fromArray(null));
        self::assertNull(ElementMoveAllowance::fromArray('yes'));
        self::assertNull(ElementMoveAllowance::fromArray([]));
        self::assertNull(ElementMoveAllowance::fromArray(['vertical' => false, 'horizontal' => false, 'maxUp' => 10]));
        // Truthy is not true — the contract is a real boolean.
        self::assertNull(ElementMoveAllowance::fromArray(['vertical' => 1, 'horizontal' => 'true']));
        self::assertNull(ElementMoveAllowance::fromCanvasObject(['type' => 'textbox']));
    }

    public function testCanonicalShapeDropsMaximaOfADisabledAxisAndInvalidValues(): void
    {
        $allowance = ElementMoveAllowance::fromArray([
            'vertical' => true,
            'horizontal' => false,
            'maxUp' => 120.004,
            'maxDown' => -5,
            'maxLeft' => 30,
            'maxRight' => 40,
            'extra' => 'ignored',
        ]);

        self::assertNotNull($allowance);
        self::assertSame([
            'vertical' => true,
            'horizontal' => false,
            'maxUp' => 120.0,
            'maxDown' => null,
            'maxLeft' => null,
            'maxRight' => null,
        ], $allowance->toArray());
    }

    public function testZeroMeansNotThatWay(): void
    {
        $onlyUp = new ElementMoveAllowance(true, false, maxUp: 40.0, maxDown: 0.0);

        self::assertSame(['minX' => 0.0, 'maxX' => 0.0, 'minY' => -40.0, 'maxY' => 0.0], $onlyUp->bounds(self::FRAME, 1080, 1350));
        self::assertSame(['x' => 0.0, 'y' => 0.0], $onlyUp->clamp(25, 25, self::FRAME, 1080, 1350));
        self::assertSame(['x' => 0.0, 'y' => -40.0], $onlyUp->clamp(-500, -500, self::FRAME, 1080, 1350));
    }

    public function testUnlimitedDirectionsStopAtTheArtworkEdges(): void
    {
        $free = new ElementMoveAllowance(true, true);

        self::assertSame(
            ['minX' => -100.0, 'maxX' => 680.0, 'minY' => -200.0, 'maxY' => 1100.0],
            $free->bounds(self::FRAME, 1080, 1350),
        );
    }

    public function testADesignedOverhangIsNeverPushedFurtherOut(): void
    {
        $free = new ElementMoveAllowance(true, true);
        $overhanging = ['left' => -20.0, 'top' => 1300.0, 'width' => 200.0, 'height' => 100.0];

        self::assertSame(
            ['minX' => 0.0, 'maxX' => 900.0, 'minY' => -1300.0, 'maxY' => 0.0],
            $free->bounds($overhanging, 1080, 1350),
        );
    }

    public function testLimitedDirectionsAreTrustedEvenPastTheEdge(): void
    {
        $limited = new ElementMoveAllowance(true, false, maxUp: 500.0, maxDown: 15.5);

        self::assertSame(['minX' => 0.0, 'maxX' => 0.0, 'minY' => -500.0, 'maxY' => 15.5], $limited->bounds(self::FRAME, 1080, 1350));
    }

    /**
     * @return iterable<string, array{float, float, array{x: float, y: float}}>
     */
    public static function clampCases(): iterable
    {
        yield 'inside' => [12.346, -7.894, ['x' => 12.35, 'y' => -7.89]];
        yield 'clamped both ways' => [-1000.0, 1000.0, ['x' => -25.0, 'y' => 60.0]];
        yield 'negative half rounds away from zero' => [-0.005, 0.005, ['x' => -0.01, 'y' => 0.01]];
        yield 'not finite' => [NAN, INF, ['x' => 0.0, 'y' => 0.0]];
        yield 'negative zero normalised' => [-0.0001, -0.0, ['x' => 0.0, 'y' => 0.0]];
    }

    /**
     * @param array{x: float, y: float} $expected
     */
    #[DataProvider('clampCases')]
    public function testClamp(float $x, float $y, array $expected): void
    {
        $allowance = new ElementMoveAllowance(true, true, maxUp: 10.0, maxDown: 60.0, maxLeft: 25.0, maxRight: 30.0);

        $clamped = $allowance->clamp($x, $y, self::FRAME, 1080, 1350);

        self::assertSame($expected, $clamped);
        // No -0.0 ever leaks (it would JSON-encode as "-0.0").
        self::assertSame('{"x":' . json_encode($expected['x']) . ',"y":' . json_encode($expected['y']) . '}', json_encode($clamped));
    }

    public function testGroupScalingUsesTheAxisRatios(): void
    {
        $allowance = new ElementMoveAllowance(true, true, maxUp: 40.0, maxDown: null, maxLeft: 10.0, maxRight: 0.0);

        self::assertSame([
            'vertical' => true,
            'horizontal' => true,
            'maxUp' => 56.89,
            'maxDown' => null,
            'maxLeft' => 20.0,
            'maxRight' => 0.0,
        ], $allowance->scaled(2.0, 1920 / 1350)->toArray());
    }

    public function testBakeMovesObjectsAndWholeContainerTrees(): void
    {
        $canvas = [
            'objects' => [
                ['type' => 'textbox', 'inputId' => 'title', 'left' => 10, 'top' => 20],
                ['type' => 'rect', 'inputId' => 'bar', 'left' => 0, 'top' => 0],
                ['type' => 'textbox', 'inputId' => 'a', 'left' => 5, 'top' => 100],
                ['type' => 'image', 'inputId' => 'slot', 'left' => 5, 'top' => 150, 'clipPath' => ['type' => 'rect', 'absolutePositioned' => true, 'left' => 5, 'top' => 150]],
                ['type' => 'textbox', 'inputId' => 'b', 'left' => 5, 'top' => 300],
            ],
            'containers' => [
                ['id' => 'root', 'maxHeight' => 500, 'memberInputIds' => ['a'], 'memberContainerIds' => ['child']],
                ['id' => 'child', 'maxHeight' => 500, 'memberInputIds' => ['slot', 'b']],
            ],
        ];

        $baked = ElementOffsets::bake($canvas, [
            'title' => ['x' => 0.0, 'y' => -12.5],
            'root' => ['x' => 3.0, 'y' => 40.0],
            'missing' => ['x' => 1.0, 'y' => 1.0],
            'bar' => ['x' => 0.0, 'y' => 0.0],
        ]);

        /** @var list<array<string, mixed>> $objects */
        $objects = $baked['objects'];
        self::assertSame([10.0, 7.5], [$objects[0]['left'], $objects[0]['top']]);
        self::assertSame([0, 0], [$objects[1]['left'], $objects[1]['top']], 'a zero offset leaves the object untouched');
        self::assertSame([8.0, 140.0], [$objects[2]['left'], $objects[2]['top']]);
        self::assertSame([8.0, 190.0], [$objects[3]['left'], $objects[3]['top']]);
        self::assertSame(['type' => 'rect', 'absolutePositioned' => true, 'left' => 8.0, 'top' => 190.0], $objects[3]['clipPath']);
        self::assertSame([8.0, 340.0], [$objects[4]['left'], $objects[4]['top']]);
        self::assertSame($canvas['containers'], $baked['containers']);
        self::assertSame(array_column($canvas['objects'], 'inputId'), array_column($objects, 'inputId'), 'order + bindings untouched');
    }
}
