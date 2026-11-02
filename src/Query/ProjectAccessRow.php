<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use WBoost\Web\Value\ProjectAvatar;

/**
 * One user ↔ project access pair for the admin users screens: either the
 * user owns the project or it is shared with them.
 */
readonly final class ProjectAccessRow
{
    public function __construct(
        public string $userId,
        public string $projectId,
        public string $projectName,
        /** Uploaded project icon (storage path), null = monogram. */
        public null|string $iconPath,
        public string $ownerName,
        public bool $isOwner,
    ) {
    }

    public function initials(): string
    {
        return ProjectAvatar::initials($this->projectName);
    }
}
