<?php

declare(strict_types=1);

namespace WBoost\Web\Message\SocialAccount;

readonly final class DisconnectFacebookAccount
{
    public function __construct(
        public string $userId,
    ) {
    }
}
