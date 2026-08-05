<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Mcp;

use Ramsey\Uuid\UuidInterface;

/**
 * Issue a personal access token for the MCP server at `/_mcp`.
 *
 * The PLAINTEXT rides in the message and only the handler hashes it — the same
 * shape as {@see \WBoost\Web\Message\User\RegisterUser}, which carries a plain
 * password its handler hashes. The caller therefore never has to know how a
 * token is stored, and gets to keep the one copy of the secret that will ever
 * exist (it is shown once and cannot be recovered afterwards).
 *
 * The plaintext must be minted by
 * {@see \WBoost\Web\Mcp\Security\McpTokenGenerator::generate()} — the wire
 * format is that class's business, and the authenticator's prefix check rejects
 * anything else before it costs a query.
 */
readonly final class CreateMcpAccessToken
{
    /**
     * @param list<string> $scopes raw scope strings, already validated against
     *                             {@see \WBoost\Web\Mcp\Security\McpScope}
     */
    public function __construct(
        public UuidInterface $tokenId,
        public UuidInterface $userId,
        public string $name,
        public array $scopes,
        public string $plainTextToken,
    ) {
    }
}
