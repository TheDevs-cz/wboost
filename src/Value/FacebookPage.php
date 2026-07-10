<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A Facebook Page the connected user manages, as returned by /me/accounts —
 * including its Page access token (SERVER-SIDE ONLY, never serialize it to
 * the browser) and the linked Instagram professional account, if any.
 */
readonly final class FacebookPage
{
    public function __construct(
        public string $id,
        public string $name,
        public string $accessToken,
        public null|string $instagramUserId,
        public null|string $instagramUsername,
    ) {
    }

    public function hasInstagram(): bool
    {
        return $this->instagramUserId !== null;
    }
}
