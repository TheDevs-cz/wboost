<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * The result of one pass over every path-bearing column in the database:
 * which storage keys are still referenced, by what, and how many rows point at
 * each one.
 */
readonly final class StorageReferenceIndex
{
    /**
     * @param array<string, StorageReference> $references keyed by storage path
     * @param array<string, int> $counts keyed by storage path
     */
    public function __construct(
        private array $references,
        private array $counts,
    ) {
    }

    public function find(string $path): null|StorageReference
    {
        return $this->references[$path] ?? null;
    }

    public function countFor(string $path): int
    {
        return $this->counts[$path] ?? 0;
    }

    /**
     * Paths the database still points at that are NOT in the bucket — a
     * dangling reference, the mirror image of an orphan (a broken logo or
     * background rather than wasted bytes).
     *
     * @param array<string, true> $existingPaths keyed by storage path
     * @return list<string>
     */
    public function danglingAgainst(array $existingPaths): array
    {
        $dangling = [];

        foreach (array_keys($this->references) as $path) {
            if (!isset($existingPaths[$path])) {
                $dangling[] = $path;
            }
        }

        return $dangling;
    }
}
