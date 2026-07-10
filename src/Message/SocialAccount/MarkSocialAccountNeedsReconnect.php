<?php

declare(strict_types=1);

namespace WBoost\Web\Message\SocialAccount;

readonly final class MarkSocialAccountNeedsReconnect
{
    public function __construct(
        public string $socialAccountId,
    ) {
    }
}
