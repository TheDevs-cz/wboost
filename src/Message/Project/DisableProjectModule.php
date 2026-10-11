<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Project;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ProjectModule;

/**
 * Switch a module off (admin only). Hides it from the navigation and the
 * dashboard; no data is deleted.
 */
readonly final class DisableProjectModule
{
    public function __construct(
        public UuidInterface $projectId,
        public ProjectModule $module,
        public UuidInterface $userId,
    ) {
    }
}
