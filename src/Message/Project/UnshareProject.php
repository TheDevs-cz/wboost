<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Project;

readonly final class UnshareProject
{
    public function __construct(
        public string $projectId,
        public string $userId,
    ) {
    }
}
