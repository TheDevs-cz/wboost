<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use WBoost\Web\Entity\FileDirectory;

/**
 * A project gallery's folder hierarchy as a pure value — depth-first order
 * (a folder, then its sub-tree, then its next sibling) with siblings kept in
 * the order given. {@see \WBoost\Web\Repository\FileDirectoryRepository::listAll()}
 * hands folders over alphabetically, the same per-level order the gallery
 * shows, so the tree reads exactly like the gallery.
 *
 * Powers the recursive "allowing a folder allows its sub-tree" rule of image
 * placeholders ({@see \WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories})
 * and the admin editor's folder tree.
 *
 * Defensive by construction: a folder whose parent is not in the list is
 * treated as a root, and a parent cycle (impossible today — folders cannot
 * be moved) still terminates, every member listed once.
 */
readonly final class GalleryFolderTree
{
    /**
     * Depth-first order.
     *
     * @var list<array{id: string, name: string, parentId: null|string, depth: int}>
     */
    public array $nodes;

    /** @var array<string, list<string>> parent id ('' = gallery root) → child ids, in order */
    private array $childIds;

    /** @var array<string, array{id: string, name: string, parentId: null|string, depth: int}> */
    private array $byId;

    /**
     * @param list<array{id: string, name: string, parentId: null|string}> $folders in sibling order
     */
    public function __construct(array $folders)
    {
        $known = [];
        foreach ($folders as $folder) {
            $known[$folder['id']] = true;
        }

        $childIds = [];
        $names = [];
        $parents = [];
        foreach ($folders as $folder) {
            $parent = $folder['parentId'] !== null && isset($known[$folder['parentId']]) && $folder['parentId'] !== $folder['id']
                ? $folder['parentId']
                : null;
            $childIds[$parent ?? ''][] = $folder['id'];
            $names[$folder['id']] = $folder['name'];
            $parents[$folder['id']] = $parent;
        }

        $nodes = [];
        $visited = [];
        $walk = static function (string $id, int $depth) use (&$walk, &$nodes, &$visited, &$parents, $childIds, $names): void {
            if (isset($visited[$id])) {
                return;
            }
            $visited[$id] = true;
            $nodes[] = ['id' => $id, 'name' => $names[$id], 'parentId' => $parents[$id], 'depth' => $depth];
            foreach ($childIds[$id] ?? [] as $childId) {
                $walk($childId, $depth + 1);
            }
        };

        foreach ($childIds[''] ?? [] as $rootId) {
            $walk($rootId, 0);
        }

        // Members of a parent cycle are unreachable from the root — surface
        // them as roots rather than losing them.
        foreach ($folders as $folder) {
            if (!isset($visited[$folder['id']])) {
                $parents[$folder['id']] = null;
                $walk($folder['id'], 0);
            }
        }

        $byId = [];
        foreach ($nodes as $node) {
            $byId[$node['id']] = $node;
        }

        $this->nodes = $nodes;
        $this->childIds = $childIds;
        $this->byId = $byId;
    }

    /**
     * @param list<FileDirectory> $directories
     */
    public static function fromDirectories(array $directories): self
    {
        return new self(array_map(
            static fn (FileDirectory $directory): array => [
                'id' => $directory->id->toString(),
                'name' => $directory->name,
                'parentId' => $directory->parent?->id->toString(),
            ],
            $directories,
        ));
    }

    /**
     * @return list<string> every folder id, depth-first
     */
    public function ids(): array
    {
        return array_map(static fn (array $node): string => $node['id'], $this->nodes);
    }

    /**
     * Direct children of a folder (null = the gallery root's top-level folders).
     *
     * @return list<array{id: string, name: string, parentId: null|string, depth: int}>
     */
    public function children(null|string $parentId): array
    {
        $children = [];
        foreach ($this->childIds[$parentId ?? ''] ?? [] as $childId) {
            if (isset($this->byId[$childId]) && $this->byId[$childId]['parentId'] === $parentId) {
                $children[] = $this->byId[$childId];
            }
        }

        return $children;
    }

    public function hasChildren(string $id): bool
    {
        return $this->children($id) !== [];
    }

    /**
     * Every folder below `$id`, at any depth, depth-first.
     *
     * @return list<string>
     */
    public function descendantIds(string $id): array
    {
        $result = [];
        foreach ($this->children($id) as $child) {
            $result[] = $child['id'];
            foreach ($this->descendantIds($child['id']) as $descendantId) {
                $result[] = $descendantId;
            }
        }

        return $result;
    }

    /**
     * The given folders plus all their descendants, in tree order; ids that
     * are not in the tree (deleted folders) drop out.
     *
     * @param list<string> $ids
     * @return list<string>
     */
    public function expand(array $ids): array
    {
        $wanted = [];
        foreach ($ids as $id) {
            if (!isset($this->byId[$id])) {
                continue;
            }
            $wanted[$id] = true;
            foreach ($this->descendantIds($id) as $descendantId) {
                $wanted[$descendantId] = true;
            }
        }

        return array_values(array_filter($this->ids(), static fn (string $id): bool => isset($wanted[$id])));
    }

    /**
     * The members of `$ids` that have no ancestor in `$ids` — the minimal
     * list that {@see expand()}s to the same set — in tree order.
     *
     * @param list<string> $ids
     * @return list<string>
     */
    public function topmost(array $ids): array
    {
        $set = array_fill_keys($ids, true);

        return array_values(array_filter($this->ids(), function (string $id) use ($set): bool {
            if (!isset($set[$id])) {
                return false;
            }
            $parentId = $this->byId[$id]['parentId'];
            while ($parentId !== null) {
                if (isset($set[$parentId])) {
                    return false;
                }
                $parentId = $this->byId[$parentId]['parentId'] ?? null;
            }

            return true;
        }));
    }
}
