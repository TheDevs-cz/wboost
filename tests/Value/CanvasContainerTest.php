<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\CanvasContainer;

/**
 * @covers \WBoost\Web\Value\CanvasContainer
 */
final class CanvasContainerTest extends TestCase
{
    public function testFromArrayBuildsContainer(): void
    {
        $container = CanvasContainer::fromArray([
            'id' => 'c-1',
            'maxHeight' => 200,
            'memberInputIds' => ['a', 'b', 'c'],
        ]);

        self::assertNotNull($container);
        self::assertSame('c-1', $container->id);
        self::assertSame(200.0, $container->maxHeight);
        self::assertSame(['a', 'b', 'c'], $container->memberInputIds);
    }

    public function testToArrayRoundTrips(): void
    {
        $data = ['id' => 'c-1', 'maxHeight' => 120.5, 'memberInputIds' => ['a', 'b']];
        $container = CanvasContainer::fromArray($data);

        self::assertNotNull($container);
        self::assertSame($data, $container->toArray());
    }

    /**
     * Inert definitions must be dropped, never crash a render.
     */
    public function testDefensiveRejections(): void
    {
        // Missing / empty id.
        self::assertNull(CanvasContainer::fromArray(['maxHeight' => 100, 'memberInputIds' => ['a', 'b']]));
        self::assertNull(CanvasContainer::fromArray(['id' => '', 'maxHeight' => 100, 'memberInputIds' => ['a', 'b']]));

        // Non-positive / non-numeric maxHeight.
        self::assertNull(CanvasContainer::fromArray(['id' => 'c', 'maxHeight' => 0, 'memberInputIds' => ['a', 'b']]));
        self::assertNull(CanvasContainer::fromArray(['id' => 'c', 'maxHeight' => -5, 'memberInputIds' => ['a', 'b']]));
        self::assertNull(CanvasContainer::fromArray(['id' => 'c', 'maxHeight' => 'tall', 'memberInputIds' => ['a', 'b']]));
        self::assertNull(CanvasContainer::fromArray(['id' => 'c', 'memberInputIds' => ['a', 'b']]));

        // Fewer than 2 usable members (non-strings are filtered first).
        self::assertNull(CanvasContainer::fromArray(['id' => 'c', 'maxHeight' => 100, 'memberInputIds' => ['a']]));
        self::assertNull(CanvasContainer::fromArray(['id' => 'c', 'maxHeight' => 100, 'memberInputIds' => ['a', 42, null]]));
        self::assertNull(CanvasContainer::fromArray(['id' => 'c', 'maxHeight' => 100, 'memberInputIds' => 'not-a-list']));
        self::assertNull(CanvasContainer::fromArray(['id' => 'c', 'maxHeight' => 100]));
    }

    public function testNonStringMemberIdsAreFiltered(): void
    {
        $container = CanvasContainer::fromArray([
            'id' => 'c',
            'maxHeight' => 100,
            'memberInputIds' => ['a', 42, '', 'b', null],
        ]);

        self::assertNotNull($container);
        self::assertSame(['a', 'b'], $container->memberInputIds);
    }

    public function testCollectionFromCanvas(): void
    {
        $canvas = [
            'objects' => [],
            'containers' => [
                ['id' => 'valid', 'maxHeight' => 100, 'memberInputIds' => ['a', 'b']],
                ['id' => 'degenerate', 'maxHeight' => 100, 'memberInputIds' => ['a']],
                'not-an-array',
                ['id' => 'valid-2', 'maxHeight' => 55.5, 'memberInputIds' => ['x', 'y', 'z']],
            ],
        ];

        $containers = CanvasContainer::collectionFromCanvas($canvas);

        self::assertCount(2, $containers);
        self::assertSame('valid', $containers[0]->id);
        self::assertSame('valid-2', $containers[1]->id);
    }

    public function testCollectionFromCanvasWithoutContainersKey(): void
    {
        self::assertSame([], CanvasContainer::collectionFromCanvas([]));
        self::assertSame([], CanvasContainer::collectionFromCanvas(['objects' => []]));
        self::assertSame([], CanvasContainer::collectionFromCanvas(['containers' => 'nope']));
    }
}
