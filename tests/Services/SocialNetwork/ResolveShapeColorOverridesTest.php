<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\SocialNetwork;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\InvalidExportValue;
use WBoost\Web\Services\SocialNetwork\ResolveShapeColorOverrides;
use WBoost\Web\Value\ShapeColorInput;
use WBoost\Web\Value\ShapeColorTarget;

/**
 * @covers \WBoost\Web\Services\SocialNetwork\ResolveShapeColorOverrides
 */
final class ResolveShapeColorOverridesTest extends TestCase
{
    private const string LISTED = '33333333-3333-4333-8333-333333333333';
    private const string FREE = '44444444-4444-4444-8444-444444444444';

    public function testResolvesValidPicksAndIgnoresTheRest(): void
    {
        $colors = (new ResolveShapeColorOverrides())->resolve(self::inputs(), [
            self::LISTED => '#C8102E',
            self::FREE => '0af',
            'unknown' => '#ffffff',
        ], lenient: false);

        self::assertSame([self::LISTED => '#c8102e', self::FREE => '#00aaff'], $colors);
    }

    public function testDesignedColourAndEmptyValuesAreNoOverride(): void
    {
        $colors = (new ResolveShapeColorOverrides())->resolve(self::inputs(), [
            self::LISTED => '#112233',
            self::FREE => '',
        ], lenient: false);

        self::assertSame([], $colors);
    }

    public function testStrictRejectsAColourOutsideTheList(): void
    {
        try {
            (new ResolveShapeColorOverrides())->resolve(self::inputs(), [self::LISTED => '#000000'], lenient: false);
            self::fail('Expected a shape_color_not_allowed 400.');
        } catch (InvalidExportValue $e) {
            self::assertSame('shape_color_not_allowed', $e->errorCode);
            self::assertSame(self::LISTED, $e->context['shapeInputId']);
            self::assertSame(['#112233', '#c8102e'], $e->context['allowedColors']);
        }
    }

    public function testStrictRejectsANonHexValue(): void
    {
        try {
            (new ResolveShapeColorOverrides())->resolve(self::inputs(), [self::FREE => 'red'], lenient: false);
            self::fail('Expected an invalid_shape_color 400.');
        } catch (InvalidExportValue $e) {
            self::assertSame('invalid_shape_color', $e->errorCode);
            self::assertSame(self::FREE, $e->context['shapeInputId']);
        }
    }

    public function testLenientDropsInvalidPicks(): void
    {
        $colors = (new ResolveShapeColorOverrides())->resolve(self::inputs(), [
            self::LISTED => '#000000',
            self::FREE => ['nope'],
        ], lenient: true);

        self::assertSame([], $colors);
    }

    /**
     * @return list<ShapeColorInput>
     */
    private static function inputs(): array
    {
        return [
            new ShapeColorInput(self::LISTED, 'Pruh', ShapeColorTarget::Fill, ['#c8102e'], false, '#112233'),
            new ShapeColorInput(self::FREE, null, ShapeColorTarget::Both, [], true, '#000000'),
        ];
    }
}
