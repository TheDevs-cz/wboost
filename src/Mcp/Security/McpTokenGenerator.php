<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Security;

/**
 * The single place that knows the MCP personal-access-token wire format.
 *
 * Wire format: `wb_mcp_<32 random bytes, base64url, unpadded>` — the prefix
 * makes a leaked token greppable in logs and recognisable to secret scanners,
 * and lets the authenticator reject a foreign bearer token before it costs a
 * database round-trip.
 *
 * The plaintext exists exactly twice: when `generate()` mints it (the CLI
 * prints it once and forgets it) and in the `Authorization` header of each
 * request. What is persisted is always `hash()`, over the WHOLE wire string
 * including the prefix — so the lookup is a plain equality match on a unique
 * indexed column. Both the token-creating command and the authenticator use
 * this class; neither re-implements the format.
 */
final class McpTokenGenerator
{
    public const string TOKEN_PREFIX = 'wb_mcp_';

    private const int SECRET_BYTES = 32;

    /**
     * Mints a fresh token. The return value is the PLAINTEXT secret — show it
     * to the user once and store only {@see hash()} of it.
     */
    public function generate(): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(self::SECRET_BYTES)), '+/', '-_'), '=');

        return self::TOKEN_PREFIX . $secret;
    }

    /**
     * sha256 hex of the whole wire token — exactly what
     * `mcp_access_token.token_hash` holds.
     */
    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Cheap shape check, so a bearer token belonging to some other scheme
     * never reaches the database.
     */
    public function looksLikeToken(string $token): bool
    {
        return str_starts_with($token, self::TOKEN_PREFIX);
    }
}
