<?php

declare(strict_types=1);

namespace WBoost\Web\Message\User;

readonly final class ChangePassword
{
    public function __construct(
        public string $userEmail,
        public string $newPlainTextPassword,
    ) {
    }
}
