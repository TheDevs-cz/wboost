<?php

declare(strict_types=1);

namespace WBoost\Web\Message\User;

readonly final class ResendInvitation
{
    public function __construct(
        public string $userId,
    ) {
    }
}
