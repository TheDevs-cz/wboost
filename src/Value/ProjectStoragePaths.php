<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * The storage a project owns: whole key prefixes that can be dropped wholesale,
 * plus individual files that sit inside a SHARED folder and must be deleted one
 * by one (the per-variant render previews).
 */
readonly final class ProjectStoragePaths
{
    /**
     * @param list<string> $directories
     * @param list<string> $files
     */
    public function __construct(
        public array $directories,
        public array $files,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->directories === [] && $this->files === [];
    }
}
