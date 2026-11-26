<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Instagram;

/**
 * Meta's deauthorize / data-deletion callback: drop every project
 * connection to this Instagram account (either of its ids).
 */
readonly final class DeleteInstagramConnections
{
    public function __construct(
        public string $instagramUserId,
    ) {
    }
}
