<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Project;

readonly final class ShareProject
{
    public function __construct(
        public string $projectId,
        public string $userId,
        public string $level,
        public null|string $sharedById,
    ) {
    }
}
