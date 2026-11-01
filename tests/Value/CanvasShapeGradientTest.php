<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\CanvasShapeGradient;
use WBoost\Web\Value\CanvasShapeGradientStop;

final class CanvasShapeGradientTest extends TestCase
{
    /**
     * The overlay case the feature exists for: white fading in from 0 % to
     * 100 % opacity. The alpha has to live INSIDE the stop colour — Fabric's
     * renderer ignores a separate `opacity` key — and the string must match
     * the editor's `gradientStopColor` byte for byte.
     */
    public function testATransparentStopCompilesToAnRgbaColour(): void
    {
        $gradient = new CanvasShapeGradient(CanvasShapeGradient::TYPE_LINEAR, 90.0, [
            CanvasShapeGradientStop::create(1.0, '#ffffff'),
            CanvasShapeGradientStop::create(0.0, '#FFFFFF', 0.0),
            CanvasShapeGradientStop::create(0.35, '#c8102e', 0.4),
        ]);

        self::assertSame([
            ['offset' => 0, 'color' => 'rgba(255,255,255,0)'],
            ['offset' => 0.35, 'color' => 'rgba(200,16,46,0.4)'],
            ['offset' => 1, 'color' => '#ffffff'],
        ], $gradient->toFabric()['colorStops']);
    }

    public function testFromFabricReadsAlphaFromRgbaAndFoldsInAStopOpacity(): void
    {
        $gradient = CanvasShapeGradient::fromFabric([
            'type' => 'linear',
            'gradientUnits' => 'percentage',
            'coords' => ['x1' => 0, 'y1' => 0.5, 'x2' => 1, 'y2' => 0.5],
            'colorStops' => [
                ['offset' => 1, 'color' => '#000'],
                ['offset' => 0, 'color' => 'rgba(255, 255, 255, 0.25)'],
                ['offset' => 0.5, 'color' => 'rgb(0,128,255)', 'opacity' => 0.5],
            ],
        ]);

        self::assertNotNull($gradient);
        self::assertSame(0.0, $gradient->angle);
        self::assertEquals([
            new CanvasShapeGradientStop(0.0, '#ffffff', 0.25),
            new CanvasShapeGradientStop(0.5, '#0080ff', 0.5),
            new CanvasShapeGradientStop(1.0, '#000000', 1.0),
        ], $gradient->stops);
    }

    public function testAMultiStopGradientRoundTripsThroughFabric(): void
    {
        $gradient = new CanvasShapeGradient(CanvasShapeGradient::TYPE_RADIAL, 90.0, [
            CanvasShapeGradientStop::create(0.0, '#ffffff', 0.0),
            CanvasShapeGradientStop::create(0.6, '#ffffff', 0.8),
            CanvasShapeGradientStop::create(1.0, '#000000'),
        ]);

        self::assertEquals($gradient, CanvasShapeGradient::fromFabric($gradient->toFabric()));
    }

    /**
     * Designs authored before multi-stop support keep their exact wire form.
     */
    public function testAnOpaqueTwoStopGradientKeepsTheFromToWireForm(): void
    {
        $gradient = CanvasShapeGradient::twoStop(CanvasShapeGradient::TYPE_LINEAR, 45.0, '#ff0000', '#0000ff');

        self::assertSame(['type' => 'linear', 'angle' => 45.0, 'from' => '#ff0000', 'to' => '#0000ff'], $gradient->toArray());
        self::assertSame([
            ['offset' => 0, 'color' => '#ff0000'],
            ['offset' => 1, 'color' => '#0000ff'],
        ], $gradient->toFabric()['colorStops']);
    }

    public function testAnUnreadableStopColourIsNotAGradientTheDslCanName(): void
    {
        self::assertNull(CanvasShapeGradient::fromFabric([
            'type' => 'linear',
            'colorStops' => [['offset' => 0, 'color' => 'hsl(0, 100%, 50%)'], ['offset' => 1, 'color' => '#000']],
        ]));
    }
}
