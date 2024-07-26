<?php

declare(strict_types=1);

namespace WBoost\Web\Message\User;

readonly final class RequestPasswordReset
{
    public function __construct(
        public string $email,
    ) {
    }
}
