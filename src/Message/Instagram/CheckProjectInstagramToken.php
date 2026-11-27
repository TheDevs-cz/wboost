<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Instagram;

/**
 * Ask Meta whether a project's Instagram token still works; a dead one
 * (revoked, expired, account moved to another portfolio) flags the
 * connection for reconnect, so the project page says so before anyone tries
 * to publish.
 */
readonly final class CheckProjectInstagramToken
{
    public function __construct(
        public string $accountId,
    ) {
    }
}
