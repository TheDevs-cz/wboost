<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Project;

readonly final class DisconnectProjectSocialAccount
{
    public function __construct(
        public string $projectId,
        public string $platform,
    ) {
    }
}
