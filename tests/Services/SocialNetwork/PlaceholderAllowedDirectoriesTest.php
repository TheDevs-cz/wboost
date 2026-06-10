<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\SocialNetwork;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories;
use WBoost\Web\Value\EditorImageInput;

/**
 * The "which folders may a placeholder be filled from" rule, isolated from the
 * database. An empty allow-list means UNRESTRICTED — the whole gallery: all
 * project folders plus the gallery root — never "none".
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
            PlaceholderAllowedDirectories::effectiveIds([self::DIR_A, self::DIR_B, self::DIR_C], []),
        );
    }

    public function testEmptyAllowListInAProjectWithNoFoldersStaysEmpty(): void
    {
        self::assertSame([], PlaceholderAllowedDirectories::effectiveIds([], []));
    }

    public function testNonEmptyAllowListIsIntersectedWithRealFolders(): void
    {
        self::assertSame(
            [self::DIR_A, self::DIR_C],
            PlaceholderAllowedDirectories::effectiveIds([self::DIR_A, self::DIR_B, self::DIR_C], [self::DIR_C, self::DIR_A]),
        );
    }

    public function testAllowedIdForADeletedFolderDropsOut(): void
    {
        self::assertSame(
            [self::DIR_A],
            PlaceholderAllowedDirectories::effectiveIds([self::DIR_A, self::DIR_B], [self::DIR_A, self::DIR_C]),
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
