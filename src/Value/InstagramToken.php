<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use SensitiveParameter;

/**
 * An Instagram user access token as Instagram Login hands it out: the
 * short-lived one from the code exchange (1 hour, carries the granted
 * permissions) or the long-lived / refreshed one (~60 days).
 */
readonly final class InstagramToken
{
    /**
     * @param list<string> $permissions empty for long-lived / refreshed tokens (the response does not list them)
     */
    public function __construct(
        #[SensitiveParameter]
        public string $accessToken,
        public null|int $expiresAtTimestamp,
        public null|string $userId = null,
        public array $permissions = [],
    ) {
    }
}
