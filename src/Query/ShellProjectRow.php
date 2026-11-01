<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use WBoost\Web\Value\ProjectAvatar;

/**
 * One project in the sidebar's "Rychlá navigace" switcher: just enough to
 * draw a row (symbol + name) and link to the project.
 */
readonly final class ShellProjectRow
{
    public function __construct(
        public string $id,
        public string $name,
        /** Uploaded project icon (storage path), null = monogram. */
        public null|string $iconPath,
    ) {
    }

    public function initials(): string
    {
        return ProjectAvatar::initials($this->name);
    }
}
