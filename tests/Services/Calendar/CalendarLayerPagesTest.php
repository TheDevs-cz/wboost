<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Calendar;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Calendar\CalendarRenderAssets;
use WBoost\Web\Services\Calendar\ImportCalendarLayout;

/**
 * Own layers on some pages (K20 P2): the picture of a layer that is only on
 * a list of pages is inlined only into those pages' chunks, and a layout
 * from another calendar loses the special-page keys that mean nothing here.
 *
 * @covers \WBoost\Web\Services\Calendar\CalendarRenderAssets
 * @covers \WBoost\Web\Services\Calendar\ImportCalendarLayout
 */
final class CalendarLayerPagesTest extends TestCase
{
    private const string STYLE_ASSET = '0190a0a0-0000-7000-8000-000000000001';
    private const string EVERYWHERE = '0190a0a0-0000-7000-8000-000000000002';
    private const string STICKER = '0190A0A0-0000-7000-8000-000000000003';
    private const string SHARED_TOO = '0190a0a0-0000-7000-8000-000000000004';

    public function testAPageLimitedPictureIsAPerPagePicture(): void
    {
        $layout = self::layout([
            'all' => ['kind' => 'image', 'pages' => 'all', 'except' => [], 'asset' => self::EVERYWHERE],
            'sticker' => ['kind' => 'image', 'pages' => ['w05', 'sp:blank1'], 'except' => [], 'asset' => self::STICKER],
            'both' => ['kind' => 'image', 'pages' => ['cover'], 'except' => [], 'asset' => self::SHARED_TOO],
            'alsoEverywhere' => ['kind' => 'image', 'pages' => 'weeks', 'except' => [], 'asset' => self::SHARED_TOO],
            'shape' => ['kind' => 'rect', 'pages' => ['w07'], 'except' => []],
        ]);

        $assets = CalendarRenderAssets::layoutAssets($layout);

        self::assertSame([self::STYLE_ASSET => true, self::EVERYWHERE => true, self::SHARED_TOO => true], $assets['shared'], 'a picture also used everywhere stays shared');
        self::assertSame([
            'w05' => [strtolower(self::STICKER) => true],
            'sp:blank1' => [strtolower(self::STICKER) => true],
            'cover' => [self::SHARED_TOO => true],
        ], $assets['perPage']);

        // the layout itself is not changed
        self::assertStringContainsString(self::STICKER, (string) json_encode($layout));
    }

    public function testALayoutWithoutLayersSharesEverything(): void
    {
        $layout = self::layout(null);

        self::assertSame(['shared' => [self::STYLE_ASSET => true], 'perPage' => []], CalendarRenderAssets::layoutAssets($layout));
    }

    public function testForeignSpecialPageKeysAreDropped(): void
    {
        $layout = self::layout([
            'list' => ['kind' => 'rect', 'pages' => ['cover', 'sp:intro', 'sp:mine', 'sp:theirs', 'w05'], 'except' => []],
            'set' => ['kind' => 'rect', 'pages' => 'all', 'except' => ['sp:theirs', 'back', 'sp:notes']],
            'gone' => ['kind' => 'rect', 'pages' => ['sp:theirs'], 'except' => []],
        ]);

        $out = ImportCalendarLayout::dropForeignPageKeys($layout, [...ImportCalendarLayout::GENERATED_SPECIAL_IDS, 'mine']);
        $layers = $out->layers;
        self::assertInstanceOf(\stdClass::class, $layers);

        self::assertEquals((object) ['kind' => 'rect', 'pages' => ['cover', 'sp:intro', 'sp:mine', 'w05'], 'except' => []], $layers->list);
        self::assertEquals((object) ['kind' => 'rect', 'pages' => 'all', 'except' => ['back', 'sp:notes']], $layers->set);
        self::assertEquals((object) ['kind' => 'rect', 'pages' => [], 'except' => []], $layers->gone, 'the layer stays, on no page');

        self::assertEquals(self::layout(null), ImportCalendarLayout::dropForeignPageKeys(self::layout(null), []), 'no layers: unchanged');
    }

    /**
     * @param null|array<string, array<string, mixed>> $layers
     */
    private static function layout(null|array $layers): \stdClass
    {
        $json = ['v' => 1, 'boxes' => (object) [], 'order' => [], 'styles' => ['logo' => ['asset' => self::STYLE_ASSET]]];

        if ($layers !== null) {
            $json['layers'] = $layers;
        }

        $decoded = json_decode((string) json_encode($json), false, 512, JSON_THROW_ON_ERROR);
        assert($decoded instanceof \stdClass);

        return $decoded;
    }
}
