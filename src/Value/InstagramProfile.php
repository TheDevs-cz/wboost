<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * `GET graph.instagram.com/me`: `userId` is the professional account id
 * (`user_id`, the one publishing addresses), `appScopedId` the app-scoped
 * `id`.
 */
readonly final class InstagramProfile
{
    public function __construct(
        public string $userId,
        public null|string $appScopedId,
        public string $username,
        public null|string $name,
        public null|string $accountType,
    ) {
    }
}
