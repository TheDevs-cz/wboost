<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class FacebookLongLivedToken
{
    public function __construct(
        public string $accessToken,
        /** Unix timestamp; null = Meta reported no expiry. */
        public null|int $expiresAtTimestamp,
    ) {
    }
}
