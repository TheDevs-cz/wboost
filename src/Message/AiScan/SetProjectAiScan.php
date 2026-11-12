<?php

declare(strict_types=1);

namespace WBoost\Web\Message\AiScan;

use Ramsey\Uuid\UuidInterface;

/**
 * The admin's per-project switch: the entitlement and the monthly limit
 * (null = no limit). Switching off cancels the project's waiting scans with a
 * refund; running ones finish, proposals stay usable.
 */
readonly final class SetProjectAiScan
{
    public function __construct(
        public UuidInterface $projectId,
        public bool $enabled,
        public null|int $monthlyLimit,
        public UuidInterface $userId,
    ) {
    }
}
