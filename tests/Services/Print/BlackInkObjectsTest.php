<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Print;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Print\BlackInkObjects;
use WBoost\Web\Value\ResolvedInputOverrides;
use WBoost\Web\Value\RichText;

/**
 * @covers \WBoost\Web\Services\Print\BlackInkObjects
 */
final class BlackInkObjectsTest extends TestCase
{
    private const array BLACK = ['#000000', '#1d1d1b'];

    public function testBlackTextsAndHairlinesButNotAreas(): void
    {
        $canvas = ['objects' => [
            ['type' => 'Rect', 'width' => 1000, 'height' => 1000, 'fill' => '#000000'],                  // 0 big black area → rich black
            ['type' => 'Textbox', 'fill' => '#000000', 'text' => 'Black'],                                // 1 ✓
            ['type' => 'Textbox', 'fill' => '#1d1d1b', 'text' => 'Brand black'],                          // 2 ✓ (manual K100)
            ['type' => 'Textbox', 'fill' => '#ff0000', 'text' => 'Red'],                                  // 3
            ['type' => 'Rect', 'shapeKind' => 'line', 'width' => 900, 'height' => 40, 'fill' => 'rgb(0, 0, 0)'], // 4 ✓ line
            ['type' => 'Rect', 'width' => 900, 'height' => 10, 'scaleY' => 2, 'fill' => '#000'],          // 5 ✓ 20 px thin
            ['type' => 'Rect', 'width' => 900, 'height' => 10, 'fill' => '#000', 'stroke' => '#ff0000', 'strokeWidth' => 2], // 6 red border
            ['type' => 'Textbox', 'fill' => '#000000', 'visible' => false],                              // 7 hidden
            ['type' => 'Image', 'fill' => '#000000'],                                                     // 8
            ['type' => 'Rect', 'width' => 900, 'height' => 10, 'fill' => ['type' => 'linear']],          // 9 gradient
        ]];

        self::assertSame([1, 2, 4, 5], BlackInkObjects::indexes($canvas, new ResolvedInputOverrides([], []), self::BLACK, 24));
    }

    public function testDesignedStylesAndUserRunsDecide(): void
    {
        $canvas = ['objects' => [
            // Designed red word inside black text, untouched by the user.
            ['type' => 'Textbox', 'fill' => '#000000', 'inputId' => 'a', 'styles' => [['start' => 0, 'end' => 3, 'style' => ['fill' => '#ff0000']]]],
            // Same design, but a plain-text fill clears the styles.
            ['type' => 'Textbox', 'fill' => '#000000', 'inputId' => 'b', 'styles' => [['start' => 0, 'end' => 3, 'style' => ['fill' => '#ff0000']]]],
            // Black design, the user painted a run red.
            ['type' => 'Textbox', 'fill' => '#000000', 'inputId' => 'c'],
            // Black design, the user's runs are black / default.
            ['type' => 'Textbox', 'fill' => '#000000', 'inputId' => 'd', 'styles' => [['start' => 0, 'end' => 3, 'style' => ['fill' => '#ff0000']]]],
        ]];

        $overrides = new ResolvedInputOverrides(
            ['b' => 'Text', 'c' => 'Ahoj', 'd' => 'Ahoj'],
            [],
            [
                'c' => RichText::fromRaw([['text' => 'Ah'], ['text' => 'oj', 'color' => '#ff0000']], false, 'c'),
                'd' => RichText::fromRaw([['text' => 'Ah', 'color' => '#000000'], ['text' => 'oj']], false, 'd'),
            ],
        );

        self::assertSame([1, 3], BlackInkObjects::indexes($canvas, $overrides, self::BLACK, 24));
    }

    public function testColourNormalization(): void
    {
        self::assertSame('#000000', BlackInkObjects::normalizeColor('#000'));
        self::assertSame('#0a0b0c', BlackInkObjects::normalizeColor('rgba(10, 11, 12, 1)'));
        self::assertNull(BlackInkObjects::normalizeColor('rgba(0, 0, 0, 0.5)'));
        self::assertNull(BlackInkObjects::normalizeColor('black'));
    }
}
