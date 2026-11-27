<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use SensitiveParameter;

/**
 * The access token Unified Login hands out: a Business Integration System
 * User token. Its lifetime comes from the Unified Login configuration —
 * `null` = never expires (what docs/instagram-setup.md tells to pick).
 */
readonly final class InstagramToken
{
    public function __construct(
        #[SensitiveParameter]
        public string $accessToken,
        public null|int $expiresAtTimestamp,
    ) {
    }
}
