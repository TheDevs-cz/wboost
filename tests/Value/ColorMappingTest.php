<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\ColorMapping;

/**
 * @covers \WBoost\Web\Value\ColorMapping
 */
final class ColorMappingTest extends TestCase
{
    /**
     * Rows stored before opacity existed have no `opacities` key — they must
     * read as fully opaque and keep every colour exactly as stored.
     */
    public function testALegacyRowReadsAsFullyOpaque(): void
    {
        $mapping = ColorMapping::fromArray([
            'background' => '65BDE6',
            'colors' => ['005086' => 'FFFFFF', '0070B8' => 'FFFFFF'],
        ]);

        self::assertSame('65BDE6', $mapping->background);
        self::assertSame(['005086' => 'FFFFFF', '0070B8' => 'FFFFFF'], $mapping->colors);
        self::assertSame([], $mapping->opacities);
        self::assertSame(100, $mapping->opacity('005086'));
    }

    public function testOpacitiesRoundTrip(): void
    {
        $mapping = new ColorMapping(null, ['005086' => 'FFFFFF'], ['005086' => 40]);

        $restored = ColorMapping::fromArray($mapping->toArray());

        self::assertSame(['005086' => 40], $restored->opacities);
        self::assertSame(40, $restored->opacity('005086'));
        self::assertSame(40, $restored->opacity('005086'));
    }

    public function testOnlyTranslucentEntriesAreKeptAndValuesAreClamped(): void
    {
        $mapping = new ColorMapping(null, [], [
            '005086' => 100,
            '0070b8' => 140,
            '65BDE6' => -3,
            'AABBCC' => 55,
        ]);

        self::assertSame(['65BDE6' => 0, 'AABBCC' => 55], $mapping->opacities);
    }

    public function testOpacityLookupIgnoresCase(): void
    {
        $mapping = new ColorMapping(null, [], ['aabbcc' => 55]);

        self::assertSame(55, $mapping->opacity('AABBCC'));
    }
}
