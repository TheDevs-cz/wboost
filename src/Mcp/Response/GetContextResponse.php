<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * The reply of the `get_context` MCP tool — the orientation call every session
 * starts with.
 *
 * `scopes` is the token's effective scope set, IMPLICATION-EXPANDED: a
 * `templates:design` token reports `templates:read` too, because that is what
 * it can actually do. It exists so an agent can tell its user "your token
 * cannot export, ask for `templates:export`" instead of discovering the
 * limitation as a 403 mid-task.
 */
readonly final class GetContextResponse
{
    /**
     * @param list<string> $scopes
     * @param list<ContextProjectResponse> $projects
     */
    public function __construct(
        public ContextUserResponse $user,
        public array $scopes,
        public array $projects,
    ) {
    }
}
