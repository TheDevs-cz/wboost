<?php

declare(strict_types=1);

namespace WBoost\Web\Message\User;

readonly final class RegisterUser
{
    public function __construct(
        public string $email,
        public string $plainTextPassword,
        public null|string $name,
    ) {
    }
}
