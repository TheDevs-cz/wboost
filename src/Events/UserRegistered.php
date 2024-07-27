<?php

declare(strict_types=1);

namespace WBoost\Web\Events;

readonly final class UserRegistered
{
    public function __construct(
        public string $email,
    ) {
    }
}
