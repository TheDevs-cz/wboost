<?php

declare(strict_types=1);

namespace WBoost\Web\Message\SocialAccount;

use SensitiveParameter;

/**
 * Link (or refresh the link of) a Facebook identity to an app user. The token
 * is the already-exchanged LONG-LIVED user token, passed plaintext and
 * encrypted by the handler before it touches the database.
 *
 * @see \WBoost\Web\MessageHandler\SocialAccount\ConnectFacebookAccountHandler
 */
readonly final class ConnectFacebookAccount
{
    /**
     * @param list<string> $scopes
     * @param null|int $tokenExpiresAtTimestamp unix timestamp, null = Meta reported no expiry
     */
    public function __construct(
        public string $userId,
        public string $providerUserId,
        #[SensitiveParameter]
        public string $accessToken,
        public null|int $tokenExpiresAtTimestamp,
        public array $scopes,
        public null|string $displayName,
    ) {
    }
}
