<?php

declare(strict_types=1);

namespace WBoost\Web\Message\AiScan;

use Ramsey\Uuid\UuidInterface;

/**
 * An admin's answer to an access request: `approve` switches AI scan on for
 * the project and e-mails the requester; otherwise the request is dismissed.
 */
readonly final class ResolveAiAccessRequest
{
    public function __construct(
        public UuidInterface $requestId,
        public UuidInterface $adminId,
        public bool $approve,
    ) {
    }
}
