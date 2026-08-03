<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A single "this DB row points at that storage key" fact, resolved all the way
 * up to the owning project + user.
 */
readonly final class StorageReference
{
    public function __construct(
        /** The `table.column` holding the path, e.g. `manual.intro_image`. */
        public string $referencedBy,
        public StorageOwner $owner,
    ) {
    }
}
