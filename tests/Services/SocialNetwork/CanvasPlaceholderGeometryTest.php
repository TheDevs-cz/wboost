<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\SocialNetwork;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;

/**
 * @covers \WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry
 */
final class CanvasPlaceholderGeometryTest extends TestCase
{
    public function testFrameFromTopLeftOriginObjectHonorsScale(): void
    {
        $frame = (new CanvasPlaceholderGeometry())->frameFromObject([
            'type' => 'Image',
            'left' => 10.0,
            'top' => 20.0,
            'width' => 100,
            'height' => 50,
            'scaleX' => 2.0,
            'scaleY' => 1.0,
            'originX' => 'left',
            'originY' => 'top',
        ]);

        self::assertNotNull($frame);
        self::assertSame(10.0, $frame->x);
        self::assertSame(20.0, $frame->y);
        self::assertSame(200.0, $frame->width);
        self::assertSame(50.0, $frame->height);
    }

    public function testFrameFromCenterOriginObjectIsConvertedToTopLeft(): void
    {
        $frame = (new CanvasPlaceholderGeometry())->frameFromObject([
            'type' => 'Image',
            'left' => 100.0,
            'top' => 100.0,
            'width' => 100,
            'height' => 100,
            'scaleX' => 1.0,
            'scaleY' => 1.0,
            'originX' => 'center',
            'originY' => 'center',
        ]);

        self::assertNotNull($frame);
        self::assertSame(50.0, $frame->x);
        self::assertSame(50.0, $frame->y);
        self::assertSame(100.0, $frame->centerX());
        self::assertSame(100.0, $frame->centerY());
    }

    public function testFrameIsNullWithoutUsableSize(): void
    {
        self::assertNull((new CanvasPlaceholderGeometry())->frameFromObject([
            'type' => 'Image',
            'left' => 0,
            'top' => 0,
        ]));
    }

    public function testFramesByInputIdReturnsOnlyFillablePlaceholders(): void
    {
        $canvas = [
            'objects' => [
                // a fillable placeholder
                ['type' => 'Image', 'inputId' => 'aaaa', 'imagePlaceholder' => true, 'left' => 0, 'top' => 0, 'width' => 200, 'height' => 100],
                // a decorative image (not a placeholder) — excluded
                ['type' => 'Image', 'inputId' => 'bbbb', 'imagePlaceholder' => false, 'left' => 0, 'top' => 0, 'width' => 10, 'height' => 10],
                // a placeholder without an inputId — excluded
                ['type' => 'Image', 'imagePlaceholder' => true, 'left' => 0, 'top' => 0, 'width' => 10, 'height' => 10],
                // a textbox — excluded
                ['type' => 'Textbox', 'inputId' => 'cccc', 'text' => 'x'],
            ],
        ];

        $frames = (new CanvasPlaceholderGeometry())->framesByInputId($canvas);

        self::assertSame(['aaaa'], array_keys($frames));
        self::assertSame(200.0, $frames['aaaa']->width);
    }

    public function testFramesByInputIdIsEmptyWithoutObjects(): void
    {
        self::assertSame([], (new CanvasPlaceholderGeometry())->framesByInputId(['version' => '5.2.4']));
    }
}
