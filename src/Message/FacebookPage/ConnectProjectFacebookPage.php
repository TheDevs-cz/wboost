<?php

declare(strict_types=1);

namespace WBoost\Web\Message\FacebookPage;

use SensitiveParameter;

/**
 * Connect (or reconnect) a project to a Facebook Page. The Page access token
 * is passed plaintext and encrypted by the handler before it touches the
 * database.
 *
 * @see \WBoost\Web\MessageHandler\FacebookPage\ConnectProjectFacebookPageHandler
 */
readonly final class ConnectProjectFacebookPage
{
    public function __construct(
        public string $projectId,
        public string $userId,
        public string $pageId,
        public null|string $tokenOwnerId,
        public string $pageName,
        #[SensitiveParameter]
        public string $pageAccessToken,
        public null|int $tokenExpiresAtTimestamp,
    ) {
    }
}
