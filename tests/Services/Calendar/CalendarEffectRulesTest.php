<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Calendar;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Services\Calendar\CalendarEffectRules;

/**
 * A page's own layer effects (`pageEffects`, K2 P2): the exact shape of an
 * item and of every effect; the vocabulary itself is pinned to the JS by
 * `CalendarEffectContractTest`.
 */
final class CalendarEffectRulesTest extends TestCase
{
    private const array SHADOW = ['kind' => 'dropShadow', 'on' => true, 'blend' => 'multiply', 'opacity' => 75, 'color' => '#abcdef', 'angle' => -60, 'distance' => 0, 'spread' => 100, 'size' => 30];

    private const array GRADIENT = ['kind' => 'gradient', 'on' => true, 'blend' => 'luminosity', 'opacity' => 0, 'type' => 'reflected', 'angle' => 359, 'scale' => 10, 'x' => -50, 'y' => 150, 'stops' => [['color' => '#000000', 'opacity' => 0, 'pos' => 0], ['color' => '#FFFFFF', 'opacity' => 100, 'pos' => 100]]];

    public function testAnItemIsRebuiltExactlyAndColoursAreUpperCase(): void
    {
        $item = CalendarEffectRules::pageItem(['photo' => [self::SHADOW, self::GRADIENT], 'logo' => [], 'head' => null, 'bg' => null]);

        self::assertSame(['photo', 'logo', 'head', 'bg'], array_keys($item));
        self::assertSame('#ABCDEF', $item['photo'][0]['color'] ?? null);
        self::assertSame(self::GRADIENT, $item['photo'][1] ?? null);
        self::assertSame([], $item['logo'], 'an empty list = no effects on this page (not the style)');
        self::assertNull($item['head']);
    }

    public function testGlowsAndInnerShadowsHaveTheirOwnShapes(): void
    {
        $glow = ['kind' => 'outerGlow', 'on' => true, 'blend' => 'screen', 'opacity' => 75, 'color' => '#FFFFBE', 'spread' => 0, 'size' => 3];
        $item = CalendarEffectRules::pageItem(['photo' => [['kind' => 'innerShadow'] + self::SHADOW, ['kind' => 'innerGlow'] + $glow], 'logo' => [$glow], 'head' => null, 'bg' => null]);

        self::assertSame(['innerShadow', 'innerGlow'], array_column($item['photo'] ?? [], 'kind'));
        self::assertSame($glow, $item['logo'][0] ?? null);

        $this->expectException(InvalidCalendarChange::class);
        CalendarEffectRules::pageItem(['photo' => [$glow + ['angle' => 120]], 'logo' => null, 'head' => null, 'bg' => null]);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function provideInvalidItems(): iterable
    {
        $item = static fn (array $photo): array => ['photo' => $photo, 'logo' => null, 'head' => null, 'bg' => null];

        yield 'a missing target' => [['photo' => null, 'logo' => null, 'head' => null]];
        yield 'an unknown target' => [['photo' => null, 'logo' => null, 'head' => null, 'bg' => null, 'qr' => null]];
        yield 'not a list' => [$item(['kind' => 'dropShadow'])];
        yield 'too many' => [$item(array_fill(0, 11, self::SHADOW))];
        yield 'an unknown kind' => [$item([['kind' => 'satin'] + self::SHADOW])];
        yield 'a missing key' => [$item([array_diff_key(self::SHADOW, ['size' => 0])])];
        yield 'an extra key' => [$item([self::SHADOW + ['noise' => 1]])];
        yield 'a blend mode the PDF has not' => [$item([['blend' => 'plus-lighter'] + self::SHADOW])];
        yield 'dissolve' => [$item([['blend' => 'dissolve'] + self::SHADOW])];
        yield 'a gradient style without SVG' => [$item([['type' => 'diamond'] + self::GRADIENT])];
        yield 'out of range' => [$item([['size' => 31] + self::SHADOW])];
        yield 'a string number' => [$item([['opacity' => '75'] + self::SHADOW])];
        yield 'not a colour' => [$item([['color' => 'black'] + self::SHADOW])];
        yield 'on is not a bool' => [$item([['on' => 1] + self::SHADOW])];
        yield 'one stop' => [$item([['stops' => [['color' => '#000000', 'opacity' => 0, 'pos' => 0]]] + self::GRADIENT])];
        yield 'a stop with an extra key' => [$item([['stops' => [['color' => '#000000', 'opacity' => 0, 'pos' => 0, 'x' => 1], ['color' => '#000000', 'opacity' => 0, 'pos' => 100]]] + self::GRADIENT])];
    }

    /**
     * @param array<mixed> $item
     */
    #[DataProvider('provideInvalidItems')]
    public function testInvalidItemsAreRefused(array $item): void
    {
        $this->expectException(InvalidCalendarChange::class);
        CalendarEffectRules::pageItem($item);
    }
}
