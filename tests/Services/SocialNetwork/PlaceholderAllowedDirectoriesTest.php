<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\SocialNetwork;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories;

/**
 * The "which folders may a placeholder be filled from" rule, isolated from the
 * database. An empty allow-list means UNRESTRICTED (all project folders) — never
 * "none" — which is what stops a folderless placeholder from 400'ing on upload.
 *
 * @covers \WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories::effectiveIds
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
}
