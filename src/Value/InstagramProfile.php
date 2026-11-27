<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One entry of `GET /me/assigned_instagram_accounts`: `userId` is the
 * Instagram professional account id publishing addresses.
 */
readonly final class InstagramProfile
{
    public function __construct(
        public string $userId,
        public string $username,
        public null|string $name = null,
    ) {
    }
}
