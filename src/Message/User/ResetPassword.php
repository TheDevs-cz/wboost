<?php

declare(strict_types=1);

namespace WBoost\Web\Message\User;

readonly final class ResetPassword
{
    public function __construct(
        public string $token,
        public string $newPlainTextPassword,
    ) {
    }
}
