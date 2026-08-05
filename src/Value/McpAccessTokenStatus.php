<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Where an MCP personal access token stands right now.
 *
 * DERIVED, never stored: it is a reading of `revoked_at` / `expires_at` against
 * an instant, which is why {@see \WBoost\Web\Entity\McpAccessToken::status()}
 * takes a `$now`. Persisting it would need a job to flip rows the moment they
 * expire, and would let the stored value disagree with the authentication
 * query — which asks the same question in SQL.
 *
 * The backed values double as the operator-facing labels printed by
 * `app:mcp:token:list`; nothing parses them back, so they are free to change.
 */
enum McpAccessTokenStatus: string
{
    /** Authenticates: not revoked, not past its expiry. */
    case Active = 'active';

    /** Killed by an operator. Terminal — a revoked token is dead, not disabled. */
    case Revoked = 'revoked';

    /** Aged out on its own. */
    case Expired = 'expired';
}
