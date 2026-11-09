<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\SocialNetwork;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\GalleryFolderTree;

/**
 * The "which folders may a placeholder be filled from" rule, isolated from the
 * database. An empty allow-list means UNRESTRICTED — the whole gallery: all
 * project folders plus the gallery root — never "none". A named folder brings
 * its whole sub-tree along (allowing a parent = allowing every descendant).
 *
 * @covers \WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories
 */
final class PlaceholderAllowedDirectoriesTest extends TestCase
{
    private const string DIR_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const string DIR_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const string DIR_C = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    public function testEmptyAllowListExpandsToAllProjectFolders(): void
    {
        self::assertSame(
            [self::DIR_A, self::DIR_B, self::DIR_C],
            PlaceholderAllowedDirectories::effectiveIds($this->flat(), []),
        );
    }

    public function testEmptyAllowListInAProjectWithNoFoldersStaysEmpty(): void
    {
        self::assertSame([], PlaceholderAllowedDirectories::effectiveIds(new GalleryFolderTree([]), []));
    }

    public function testNonEmptyAllowListIsIntersectedWithRealFolders(): void
    {
        self::assertSame(
            [self::DIR_A, self::DIR_C],
            PlaceholderAllowedDirectories::effectiveIds($this->flat(), [self::DIR_C, self::DIR_A]),
        );
    }

    public function testAllowedIdForADeletedFolderDropsOut(): void
    {
        self::assertSame(
            [self::DIR_A],
            PlaceholderAllowedDirectories::effectiveIds(
                new GalleryFolderTree([
                    ['id' => self::DIR_A, 'name' => 'A', 'parentId' => null],
                    ['id' => self::DIR_B, 'name' => 'B', 'parentId' => null],
                ]),
                [self::DIR_A, self::DIR_C],
            ),
        );
    }

    public function testAnAllowedParentIncludesEveryDescendantRecursively(): void
    {
        // A > B > C: allowing A alone opens the whole branch, in tree order.
        self::assertSame(
            [self::DIR_A, self::DIR_B, self::DIR_C],
            PlaceholderAllowedDirectories::effectiveIds($this->chain(), [self::DIR_A]),
        );
    }

    public function testAnAllowedChildDoesNotOpenItsParent(): void
    {
        self::assertSame(
            [self::DIR_B, self::DIR_C],
            PlaceholderAllowedDirectories::effectiveIds($this->chain(), [self::DIR_B]),
        );
    }

    public function testIntersectionOfAParentAndItsChildIsTheChildBranch(): void
    {
        self::assertSame(
            [self::DIR_B, self::DIR_C],
            PlaceholderAllowedDirectories::intersectEffective($this->chain(), [[self::DIR_A], [self::DIR_B]]),
        );
    }

    public function testIntersectionOfSiblingsIsEmpty(): void
    {
        self::assertSame(
            [],
            PlaceholderAllowedDirectories::intersectEffective($this->flat(), [[self::DIR_A], [self::DIR_B]]),
        );
    }

    public function testIntersectionOfASingleListIsItsExpansion(): void
    {
        self::assertSame(
            [self::DIR_A, self::DIR_B, self::DIR_C],
            PlaceholderAllowedDirectories::intersectEffective($this->chain(), [[self::DIR_A]]),
        );
    }

    public function testOnlyAnEmptyAllowListIncludesTheGalleryRoot(): void
    {
        // includesRoot never touches the database; the repository is final, so
        // build a real one over a stubbed entity manager.
        $service = new PlaceholderAllowedDirectories(
            new FileDirectoryRepository($this->createStub(EntityManagerInterface::class)),
        );

        self::assertTrue($service->includesRoot($this->input([])));
        self::assertFalse($service->includesRoot($this->input([self::DIR_A])));
    }

    /** Three top-level folders. */
    private function flat(): GalleryFolderTree
    {
        return new GalleryFolderTree([
            ['id' => self::DIR_A, 'name' => 'A', 'parentId' => null],
            ['id' => self::DIR_B, 'name' => 'B', 'parentId' => null],
            ['id' => self::DIR_C, 'name' => 'C', 'parentId' => null],
        ]);
    }

    /** A > B > C. */
    private function chain(): GalleryFolderTree
    {
        return new GalleryFolderTree([
            ['id' => self::DIR_A, 'name' => 'A', 'parentId' => null],
            ['id' => self::DIR_B, 'name' => 'B', 'parentId' => self::DIR_A],
            ['id' => self::DIR_C, 'name' => 'C', 'parentId' => self::DIR_B],
        ]);
    }

    /**
     * @param list<string> $allowedDirectoryIds
     */
    private function input(array $allowedDirectoryIds): EditorImageInput
    {
        return new EditorImageInput(
            'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            null,
            null,
            true,
            true,
            true,
            true,
            $allowedDirectoryIds,
        );
    }
}
