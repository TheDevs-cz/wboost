<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\SocialNetwork;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\SocialNetwork\ImagePlacement;
use WBoost\Web\Value\PlaceholderFrame;

/**
 * @covers \WBoost\Web\Services\SocialNetwork\ImagePlacement
 */
final class ImagePlacementTest extends TestCase
{
    public function testCentersWithObjectContainByDefault(): void
    {
        // 100×100 frame at origin, a 200×100 (wide) image → contain scale 0.5.
        $result = (new ImagePlacement())->compute(
            new PlaceholderFrame(0.0, 0.0, 100.0, 100.0),
            200,
            100,
            scale: 1.0,
            offsetX: 0.0,
            offsetY: 0.0,
            rotation: 0.0,
        );

        self::assertSame(50.0, $result['left']);
        self::assertSame(50.0, $result['top']);
        self::assertSame('center', $result['originX']);
        self::assertSame('center', $result['originY']);
        self::assertSame(0.5, $result['scaleX']);
        self::assertSame(0.5, $result['scaleY']);
        self::assertSame(0.0, $result['angle']);
        self::assertSame(200, $result['width']);
        self::assertSame(100, $result['height']);
    }

    public function testScaleMultipliesTheContainFit(): void
    {
        $result = (new ImagePlacement())->compute(
            new PlaceholderFrame(0.0, 0.0, 100.0, 100.0),
            200,
            100,
            scale: 2.0,
            offsetX: 0.0,
            offsetY: 0.0,
            rotation: 0.0,
        );

        // contain 0.5 × scale 2 = 1.0
        self::assertSame(1.0, $result['scaleX']);
        self::assertSame(1.0, $result['scaleY']);
    }

    public function testOffsetPansFromFrameCentreAndRotationApplies(): void
    {
        $result = (new ImagePlacement())->compute(
            new PlaceholderFrame(200.0, 100.0, 50.0, 50.0),
            50,
            50,
            scale: 1.0,
            offsetX: 10.0,
            offsetY: -5.0,
            rotation: 30.0,
        );

        // frame centre (225,125) + offset (10,-5)
        self::assertSame(235.0, $result['left']);
        self::assertSame(120.0, $result['top']);
        self::assertSame(30.0, $result['angle']);
        self::assertSame(1.0, $result['scaleX']);
    }

    public function testClipPathMatchesTheFrame(): void
    {
        $result = (new ImagePlacement())->compute(
            new PlaceholderFrame(10.0, 20.0, 80.0, 40.0),
            80,
            40,
            scale: 1.0,
            offsetX: 0.0,
            offsetY: 0.0,
            rotation: 0.0,
        );

        $clip = $result['clipPath'];
        self::assertIsArray($clip);
        self::assertSame('Rect', $clip['type']);
        self::assertTrue($clip['absolutePositioned']);
        self::assertSame('center', $clip['originX']);
        self::assertSame('center', $clip['originY']);
        self::assertSame(50.0, $clip['left']);  // 10 + 80/2
        self::assertSame(40.0, $clip['top']);   // 20 + 40/2
        self::assertSame(80.0, $clip['width']);
        self::assertSame(40.0, $clip['height']);
    }

    public function testTallImageContainsByHeight(): void
    {
        // 100×100 frame, a 100×400 (tall) image → contain scale 0.25.
        $result = (new ImagePlacement())->compute(
            new PlaceholderFrame(0.0, 0.0, 100.0, 100.0),
            100,
            400,
            scale: 1.0,
            offsetX: 0.0,
            offsetY: 0.0,
            rotation: 0.0,
        );

        self::assertSame(0.25, $result['scaleX']);
        self::assertSame(0.25, $result['scaleY']);
    }

    public function testRatioPanResolvesAgainstTheFrameEdges(): void
    {
        // A quarter of the frame right and a tenth down, in a 200×100 frame.
        $result = (new ImagePlacement())->compute(
            new PlaceholderFrame(0.0, 0.0, 200.0, 100.0),
            200,
            100,
            scale: 1.0,
            offsetX: 0.0,
            offsetY: 0.0,
            rotation: 0.0,
            offsetXRatio: 0.25,
            offsetYRatio: 0.1,
        );

        self::assertSame(150.0, $result['left']);  // centre 100 + 0.25 × 200
        self::assertSame(60.0, $result['top']);    // centre  50 + 0.10 × 100
    }

    public function testTheSameRatioCarriesOneCropIntoAnotherDimension(): void
    {
        // What makes the ratio form portable: the identical placement lands a
        // quarter-frame right of centre in BOTH frames, while a pixel offset
        // would mean a different crop in each.
        $placement = new ImagePlacement();

        $square = $placement->compute(
            new PlaceholderFrame(0.0, 0.0, 400.0, 400.0),
            800,
            800,
            scale: 1.0,
            offsetX: 0.0,
            offsetY: 0.0,
            rotation: 0.0,
            offsetXRatio: 0.25,
            offsetYRatio: 0.0,
        );

        $story = $placement->compute(
            new PlaceholderFrame(0.0, 0.0, 200.0, 600.0),
            800,
            800,
            scale: 1.0,
            offsetX: 0.0,
            offsetY: 0.0,
            rotation: 0.0,
            offsetXRatio: 0.25,
            offsetYRatio: 0.0,
        );

        self::assertSame(300.0, $square['left']);  // 200 + 0.25 × 400
        self::assertSame(150.0, $story['left']);   // 100 + 0.25 × 200
    }

    public function testRatioWinsOverThePixelFormPerAxis(): void
    {
        $result = (new ImagePlacement())->compute(
            new PlaceholderFrame(0.0, 0.0, 100.0, 100.0),
            100,
            100,
            scale: 1.0,
            offsetX: 40.0,
            offsetY: 40.0,
            rotation: 0.0,
            offsetXRatio: 0.1,
            offsetYRatio: null,
        );

        self::assertSame(60.0, $result['left']); // ratio: 50 + 0.1 × 100
        self::assertSame(90.0, $result['top']);  // px kept for the axis with no ratio
    }
}
