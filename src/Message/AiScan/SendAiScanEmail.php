<?php

declare(strict_types=1);

namespace WBoost\Web\Message\AiScan;

use Ramsey\Uuid\UuidInterface;

/**
 * The one e-mail about a scan that ended (ux.md §7) — dispatched with a
 * delay when the scan ends and sent only if the requester has still not seen
 * the result by then — or, with `reminder`, the one reminder three days
 * before a waiting proposal expires.
 *
 * Routed to the `async` transport. The handler decides everything again from
 * the row, so a late, repeated or stale message sends nothing twice.
 */
readonly final class SendAiScanEmail
{
    public function __construct(
        public UuidInterface $scanId,
        public bool $reminder = false,
    ) {
    }
}
