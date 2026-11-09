<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\GalleryFolderTree;

/**
 * The gallery's folder hierarchy as a pure value: depth-first order with
 * siblings in the order given (the repository hands them over alphabetically,
 * so the result matches the gallery), descendants and expansion.
 *
 * @covers \WBoost\Web\Value\GalleryFolderTree
 */
final class GalleryFolderTreeTest extends TestCase
{
    private const string BACKGROUNDS = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const string FOOTBALL = 'aaaaaaaa-0000-4000-8000-000000000002';
    private const string PHOTOS = 'aaaaaaaa-0000-4000-8000-000000000003';
    private const string NIGHT = 'aaaaaaaa-0000-4000-8000-000000000004';
    private const string LOGOS = 'aaaaaaaa-0000-4000-8000-000000000005';

    public function testOrdersDepthFirstKeepingSiblingOrder(): void
    {
        $tree = $this->tree();

        self::assertSame(
            [self::LOGOS, self::BACKGROUNDS, self::FOOTBALL, self::NIGHT, self::PHOTOS],
            $tree->ids(),
        );
        self::assertSame([0, 0, 1, 2, 1], array_map(static fn (array $node): int => $node['depth'], $tree->nodes));
    }

    public function testChildrenAndHasChildren(): void
    {
        $tree = $this->tree();

        self::assertSame([self::LOGOS, self::BACKGROUNDS], array_map(static fn (array $node): string => $node['id'], $tree->children(null)));
        self::assertSame([self::FOOTBALL, self::PHOTOS], array_map(static fn (array $node): string => $node['id'], $tree->children(self::BACKGROUNDS)));
        self::assertTrue($tree->hasChildren(self::FOOTBALL));
        self::assertFalse($tree->hasChildren(self::LOGOS));
    }

    public function testDescendantsAreRecursive(): void
    {
        self::assertSame([self::FOOTBALL, self::NIGHT, self::PHOTOS], $this->tree()->descendantIds(self::BACKGROUNDS));
        self::assertSame([], $this->tree()->descendantIds(self::LOGOS));
        self::assertSame([], $this->tree()->descendantIds('ffffffff-0000-4000-8000-000000000000'));
    }

    public function testExpandAddsDescendantsInTreeOrderAndDropsUnknownIds(): void
    {
        self::assertSame(
            [self::LOGOS, self::FOOTBALL, self::NIGHT],
            $this->tree()->expand([self::LOGOS, self::FOOTBALL, 'ffffffff-0000-4000-8000-000000000000']),
        );
    }

    public function testTopmostKeepsOnlyIdsWithoutAnAncestorInTheSet(): void
    {
        self::assertSame(
            [self::LOGOS, self::BACKGROUNDS],
            $this->tree()->topmost([self::NIGHT, self::LOGOS, self::BACKGROUNDS, self::FOOTBALL]),
        );
    }

    public function testAFolderWhoseParentIsMissingBecomesARoot(): void
    {
        $tree = new GalleryFolderTree([
            ['id' => self::NIGHT, 'name' => 'Noc', 'parentId' => 'ffffffff-0000-4000-8000-000000000000'],
            ['id' => self::LOGOS, 'name' => 'Logotypy', 'parentId' => null],
        ]);

        self::assertSame([self::NIGHT, self::LOGOS], $tree->ids());
    }

    public function testACycleCannotLoopForever(): void
    {
        $tree = new GalleryFolderTree([
            ['id' => self::NIGHT, 'name' => 'A', 'parentId' => self::LOGOS],
            ['id' => self::LOGOS, 'name' => 'B', 'parentId' => self::NIGHT],
        ]);

        self::assertCount(2, $tree->ids());
    }

    private function tree(): GalleryFolderTree
    {
        // Alphabetical across the whole project, as FileDirectoryRepository::listAll returns them.
        return new GalleryFolderTree([
            ['id' => self::LOGOS, 'name' => 'Logotypy', 'parentId' => null],
            ['id' => self::NIGHT, 'name' => 'Noc', 'parentId' => self::FOOTBALL],
            ['id' => self::BACKGROUNDS, 'name' => 'Pozadí', 'parentId' => null],
            ['id' => self::FOOTBALL, 'name' => 'Pozadí Fotbal', 'parentId' => self::BACKGROUNDS],
            ['id' => self::PHOTOS, 'name' => 'Pozadí fotografie', 'parentId' => self::BACKGROUNDS],
        ]);
    }
}
