<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Project;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ProjectModule;

/**
 * Switch a module on. When the person doing it is not an admin (a project
 * owner discovering the module themselves), the admins get an e-mail.
 */
readonly final class EnableProjectModule
{
    public function __construct(
        public UuidInterface $projectId,
        public ProjectModule $module,
        public UuidInterface $userId,
    ) {
    }
}
