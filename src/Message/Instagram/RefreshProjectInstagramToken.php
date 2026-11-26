<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Instagram;

/**
 * Swap a project's long-lived Instagram token for a fresh 60-day one (only
 * when it is due — see ProjectInstagramAccount::isDueForRefresh()).
 */
readonly final class RefreshProjectInstagramToken
{
    public function __construct(
        public string $accountId,
        public bool $force = false,
    ) {
    }
}
