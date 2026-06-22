<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\SocialNetwork;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Value\EditorTextInput;

/**
 * @covers \WBoost\Web\Services\SocialNetwork\TextInputObjectBinder
 */
final class TextInputObjectBinderTest extends TestCase
{
    private function binder(): TextInputObjectBinder
    {
        return new TextInputObjectBinder(new CanvasPlaceholderGeometry());
    }

    private function input(string $inputId): EditorTextInput
    {
        return new EditorTextInput($inputId, 'Name', null, false, false, null, false);
    }

    public function testBindsIthTextboxToIthInputSkippingNonTextboxes(): void
    {
        $canvas = [
            'objects' => [
                // index 0: image — skipped, never consumes an input slot
                ['type' => 'Image', 'left' => 0, 'top' => 0, 'width' => 10, 'height' => 10],
                // index 1: first textbox → inputs[0]
                ['type' => 'Textbox', 'left' => 5, 'top' => 6, 'width' => 100, 'height' => 20],
                // index 2: second textbox → inputs[1]
                ['type' => 'Textbox', 'left' => 7, 'top' => 8, 'width' => 200, 'height' => 30],
            ],
        ];

        $inputs = [$this->input('id-a'), $this->input('id-b')];

        self::assertSame(
            [1 => 'id-a', 2 => 'id-b'],
            $this->binder()->inputIdByObjectIndex($canvas, $inputs),
        );
    }

    public function testExtraTextboxesWithoutAnInputAreNotBound(): void
    {
        $canvas = [
            'objects' => [
                ['type' => 'Textbox', 'left' => 0, 'top' => 0, 'width' => 100, 'height' => 20],
                ['type' => 'Textbox', 'left' => 0, 'top' => 0, 'width' => 100, 'height' => 20],
            ],
        ];

        // Only one input — the second textbox has no counterpart.
        self::assertSame(
            [0 => 'only'],
            $this->binder()->inputIdByObjectIndex($canvas, [$this->input('only')]),
        );
    }

    public function testFramesByInputIdUsesDisplayedBoundingBox(): void
    {
        $canvas = [
            'objects' => [
                ['type' => 'Image', 'left' => 0, 'top' => 0, 'width' => 10, 'height' => 10],
                [
                    'type' => 'Textbox',
                    'left' => 80, 'top' => 60, 'width' => 200, 'height' => 40,
                    'scaleX' => 2.0, 'scaleY' => 1.0, 'originX' => 'left', 'originY' => 'top',
                ],
            ],
        ];

        $frames = $this->binder()->framesByInputId($canvas, [$this->input('headline')]);

        self::assertSame(['headline'], array_keys($frames));
        self::assertSame(80.0, $frames['headline']->x);
        self::assertSame(60.0, $frames['headline']->y);
        self::assertSame(400.0, $frames['headline']->width);
        self::assertSame(40.0, $frames['headline']->height);
    }

    public function testEmptyWithoutObjects(): void
    {
        self::assertSame([], $this->binder()->inputIdByObjectIndex(['version' => '5'], [$this->input('x')]));
        self::assertSame([], $this->binder()->framesByInputId(['version' => '5'], [$this->input('x')]));
    }
}
