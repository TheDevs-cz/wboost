<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Instagram;

use SensitiveParameter;

/**
 * Connect (or reconnect) a project to an Instagram professional account. The
 * token is the already-exchanged LONG-LIVED one, passed plaintext and
 * encrypted by the handler before it touches the database.
 *
 * @see \WBoost\Web\MessageHandler\Instagram\ConnectProjectInstagramHandler
 */
readonly final class ConnectProjectInstagram
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $projectId,
        public string $userId,
        public string $igUserId,
        public null|string $appScopedUserId,
        public string $username,
        public null|string $name,
        public null|string $accountType,
        #[SensitiveParameter]
        public string $accessToken,
        public null|int $tokenExpiresAtTimestamp,
        public array $scopes,
    ) {
    }
}
