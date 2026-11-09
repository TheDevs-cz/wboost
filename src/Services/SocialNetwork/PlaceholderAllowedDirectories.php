<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\FileSource;
use WBoost\Web\Value\GalleryFolderTree;

/**
 * Single source of truth for "which gallery folders may an image placeholder be
 * filled from". An empty {@see EditorImageInput::$allowedDirectoryIds} means the
 * designer left the slot UNRESTRICTED → the whole project gallery is open:
 * every project-gallery folder AND the gallery root (files in no folder) —
 * see {@see self::includesRoot()} (the admin editor warns the designer of this
 * when they leave it empty). A non-empty list is intersected with the project's
 * real folders, so a folder deleted after the designer picked it simply drops
 * out; the root can never be on an explicit list.
 *
 * **Allowing a folder allows its whole sub-tree** (recursively, 2026-11-09):
 * the designer may tick a parent once instead of every child, and a folder
 * created under it later is open too. Ticking only children stays possible —
 * a child never opens its parent. Resolved lists come back in gallery tree
 * order (a folder, then its sub-folders).
 *
 * Every fill path (web pick list, API gallery list, upload target, render-time
 * validation) resolves through here so they can never disagree about what is
 * allowed.
 */
readonly final class PlaceholderAllowedDirectories
{
    public function __construct(
        private FileDirectoryRepository $fileDirectoryRepository,
    ) {
    }

    /**
     * Whether the slot may also use the gallery ROOT (files in no folder) — true
     * exactly for unrestricted slots: an explicit allow-list can only name
     * folders, so picking any folder implicitly excludes the root.
     */
    public function includesRoot(EditorImageInput $input): bool
    {
        return $input->allowedDirectoryIds === [];
    }

    /**
     * @return list<FileDirectory> depth-first (gallery tree order)
     */
    public function resolve(EditorImageInput $input, UuidInterface $projectId): array
    {
        $all = $this->fileDirectoryRepository->listAll($projectId, FileSource::ProjectImage);
        $byId = [];
        foreach ($all as $directory) {
            $byId[$directory->id->toString()] = $directory;
        }

        return array_map(
            static fn (string $id): FileDirectory => $byId[$id],
            self::effectiveIds(GalleryFolderTree::fromDirectories($all), $input->allowedDirectoryIds),
        );
    }

    /**
     * @return list<string>
     */
    public function resolveIds(EditorImageInput $input, UuidInterface $projectId): array
    {
        return self::effectiveIds($this->tree($projectId), $input->allowedDirectoryIds);
    }

    /**
     * Folders that pass EVERY restricted slot of `$inputs` — the shared
     * fields of multi-page documents. Unrestricted slots narrow nothing, so
     * null = no slot is restricted (any folder, root included). See
     * {@see self::intersectEffective()}.
     *
     * @param list<EditorImageInput> $inputs
     * @return null|list<string>
     */
    public function intersectIds(array $inputs, UuidInterface $projectId): null|array
    {
        $restricted = array_values(array_filter(
            array_map(static fn (EditorImageInput $input): array => $input->allowedDirectoryIds, $inputs),
            static fn (array $ids): bool => $ids !== [],
        ));

        if ($restricted === []) {
            return null;
        }

        return self::intersectEffective($this->tree($projectId), $restricted);
    }

    public function tree(UuidInterface $projectId): GalleryFolderTree
    {
        return GalleryFolderTree::fromDirectories(
            $this->fileDirectoryRepository->listAll($projectId, FileSource::ProjectImage),
        );
    }

    /**
     * The rule, isolated and pure: an empty allow-list expands to EVERY project
     * folder (unrestricted); otherwise each named folder that still exists
     * brings its whole sub-tree along, and ids that no longer exist drop out.
     * Tree order either way.
     *
     * @param list<string> $allowedDirectoryIds
     * @return list<string>
     */
    public static function effectiveIds(GalleryFolderTree $tree, array $allowedDirectoryIds): array
    {
        if ($allowedDirectoryIds === []) {
            return $tree->ids();
        }

        return $tree->expand($allowedDirectoryIds);
    }

    /**
     * Intersection of several RESTRICTED (non-empty) allow-lists, each taken
     * at its effective (sub-tree-expanded) value — so a parent on one list
     * and its child on another meet at the child's branch, which raw id
     * intersection would miss. The result is itself sub-tree-closed: feeding
     * it back through {@see self::effectiveIds()} changes nothing.
     *
     * @param non-empty-list<list<string>> $allowLists
     * @return list<string>
     */
    public static function intersectEffective(GalleryFolderTree $tree, array $allowLists): array
    {
        $result = self::effectiveIds($tree, $allowLists[0]);
        foreach (array_slice($allowLists, 1) as $allowList) {
            $result = array_values(array_intersect($result, self::effectiveIds($tree, $allowList)));
        }

        return $result;
    }
}
