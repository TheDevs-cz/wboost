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
        $data = [
            'id' => 'c-1',
            'maxHeight' => 120.5,
            'memberInputIds' => ['a', 'b'],
            'memberContainerIds' => ['child-1'],
            'gap' => 12.0,
            'spaceAfter' => 24.0,
        ];
        $container = CanvasContainer::fromArray($data);

        self::assertNotNull($container);
        self::assertSame($data, $container->toArray());
    }

    public function testLegacyEntryGetsNullGapAndNoChildren(): void
    {
        $container = CanvasContainer::fromArray([
            'id' => 'c-1',
            'maxHeight' => 100,
            'memberInputIds' => ['a', 'b'],
        ]);

        self::assertNotNull($container);
        self::assertSame([], $container->memberContainerIds);
        self::assertNull($container->gap);
        self::assertNull($container->spaceAfter);
    }

    public function testNestingRules(): void
    {
        // One input + one child = 2 members → valid.
        $parent = CanvasContainer::fromArray([
            'id' => 'parent',
            'maxHeight' => 300,
            'memberInputIds' => ['a'],
            'memberContainerIds' => ['child'],
        ]);
        self::assertNotNull($parent);
        self::assertSame(['child'], $parent->memberContainerIds);

        // Children alone can carry a container too.
        $grouping = CanvasContainer::fromArray([
            'id' => 'grouping',
            'maxHeight' => 300,
            'memberContainerIds' => ['s1', 's2'],
        ]);
        self::assertNotNull($grouping);
        self::assertSame([], $grouping->memberInputIds);

        // Self-references are stripped (and can invalidate the entry).
        self::assertNull(CanvasContainer::fromArray([
            'id' => 'self',
            'maxHeight' => 100,
            'memberInputIds' => ['a'],
            'memberContainerIds' => ['self'],
        ]));

        $nested = CanvasContainer::fromArray([
            'id' => 'child',
            'maxHeight' => 100,
            'memberInputIds' => ['a', 'b'],
        ]);
        self::assertNotNull($nested);
        self::assertTrue($nested->isNestedIn([$nested, $parent]));
        self::assertFalse($parent->isNestedIn([$nested, $parent]));
    }

    public function testInvalidGapFallsBackToDesignedGaps(): void
    {
        foreach (['wide', -3, NAN] as $invalid) {
            $container = CanvasContainer::fromArray([
                'id' => 'c',
                'maxHeight' => 100,
                'memberInputIds' => ['a', 'b'],
                'gap' => $invalid,
            ]);
            self::assertNotNull($container);
            self::assertNull($container->gap);
        }

        $zeroGap = CanvasContainer::fromArray([
            'id' => 'c',
            'maxHeight' => 100,
            'memberInputIds' => ['a', 'b'],
            'gap' => 0,
        ]);
        self::assertNotNull($zeroGap);
        self::assertSame(0.0, $zeroGap->gap);
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

    public function testHidableAndNameAreParsedAndEmittedOnlyWhenSet(): void
    {
        $plain = CanvasContainer::fromArray(['id' => 'c', 'maxHeight' => 10, 'memberInputIds' => ['a', 'b']]);
        self::assertNotNull($plain);
        self::assertFalse($plain->hidable);
        self::assertNull($plain->name);
        self::assertArrayNotHasKey('hidable', $plain->toArray());
        self::assertArrayNotHasKey('name', $plain->toArray());

        $data = [
            'id' => 'c',
            'maxHeight' => 10.0,
            'memberInputIds' => ['a', 'b'],
            'memberContainerIds' => [],
            'gap' => null,
            'spaceAfter' => null,
            'hidable' => true,
            'name' => 'Bublina',
        ];
        $hidable = CanvasContainer::fromArray($data);
        self::assertNotNull($hidable);
        self::assertTrue($hidable->hidable);
        self::assertSame('Bublina', $hidable->name);
        self::assertSame($data, $hidable->toArray());
    }

    public function testNonBooleanHidableAndBlankNameAreIgnored(): void
    {
        $container = CanvasContainer::fromArray([
            'id' => 'c',
            'maxHeight' => 10,
            'memberInputIds' => ['a', 'b'],
            'hidable' => '1',
            'name' => '   ',
        ]);

        self::assertNotNull($container);
        self::assertFalse($container->hidable);
        self::assertNull($container->name);
    }

    public function testDeepMemberInputIdsWalksTheTreeCycleSafely(): void
    {
        $containers = CanvasContainer::collectionFromCanvas(['containers' => [
            ['id' => 'root', 'maxHeight' => 100, 'memberInputIds' => ['t1'], 'memberContainerIds' => ['child']],
            ['id' => 'child', 'maxHeight' => 100, 'memberInputIds' => ['t2', 'img'], 'memberContainerIds' => ['grand', 'root']],
            ['id' => 'grand', 'maxHeight' => 100, 'memberInputIds' => ['t3', 'shape']],
        ]]);

        $root = CanvasContainer::findById($containers, 'root');
        self::assertNotNull($root);
        self::assertSame(['t1', 't2', 'img', 't3', 'shape'], $root->deepMemberInputIds($containers));
        self::assertNull(CanvasContainer::findById($containers, 'nope'));
    }

    public function testHiddenMemberInputIdsExpandsOnlyHidableContainers(): void
    {
        $containers = CanvasContainer::collectionFromCanvas(['containers' => [
            ['id' => 'bubble', 'maxHeight' => 100, 'memberInputIds' => ['text', 'bubble-img'], 'hidable' => true],
            ['id' => 'locked', 'maxHeight' => 100, 'memberInputIds' => ['x', 'y']],
            ['id' => 'outer', 'maxHeight' => 100, 'memberInputIds' => ['z'], 'memberContainerIds' => ['locked'], 'hidable' => true],
        ]]);

        self::assertSame(['text', 'bubble-img'], CanvasContainer::hiddenMemberInputIds($containers, ['bubble']));
        // Not hidable → nothing; unknown id → nothing.
        self::assertSame([], CanvasContainer::hiddenMemberInputIds($containers, ['locked', 'unknown']));
        // A hidable PARENT hides its non-hidable child's members too.
        self::assertSame(['z', 'x', 'y'], CanvasContainer::hiddenMemberInputIds($containers, ['outer']));
    }
}
