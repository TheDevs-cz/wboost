<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Project;

readonly final class MarkProjectSocialAccountNeedsReconnect
{
    public function __construct(
        public string $accountId,
    ) {
    }
}
