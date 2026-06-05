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
}
