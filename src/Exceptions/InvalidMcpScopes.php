<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use WBoost\Web\Mcp\Security\McpScope;

/**
 * A human named MCP scopes that cannot be honoured — an unknown value, or none
 * at all. Raised by {@see \WBoost\Web\Mcp\Security\McpScopeSelection::parse()}.
 *
 * The message is operator-facing: `app:mcp:token:create` prints it verbatim, so
 * every variant names the offending input AND lists the valid scopes — the
 * mistake is nearly always a typo and the answer belongs on the same screen.
 *
 * No `#[WithHttpStatus]` on purpose: the only input surface that can raise this
 * is the CLI, so a status code would be a promise nothing keeps.
 */
final class InvalidMcpScopes extends \Exception
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function unknownScope(string $value): self
    {
        return new self(sprintf(
            'Unknown scope "%s". Valid scopes: %s.',
            $value,
            implode(', ', McpScope::values()),
        ));
    }

    public static function noScopeGiven(): self
    {
        return new self(sprintf(
            'At least one scope is required. Valid scopes: %s.',
            implode(', ', McpScope::values()),
        ));
    }
}
