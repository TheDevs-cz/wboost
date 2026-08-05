<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Mcp;

use Ramsey\Uuid\UuidInterface;

/**
 * Kill an MCP personal access token. Idempotent — revoking an already revoked
 * token keeps the original timestamp, and the row survives so the listing can
 * still show that the token existed and when it died.
 */
readonly final class RevokeMcpAccessToken
{
    public function __construct(
        public UuidInterface $tokenId,
    ) {
    }
}
