<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Fixtures;

use Mcp\Capability\Attribute\McpTool;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;

/**
 * A `templates:read` tool. Test environment only.
 *
 * Together with {@see ScopeDesignProbeTool} and {@see UnscopedProbeTool} it
 * gives `tests/Mcp/ScopeFilteringTest.php` one tool per tier, so the test can
 * assert the WHOLE visible set for a token rather than "the design tool is
 * absent" — a filter that hid everything would pass the negative assertion.
 *
 * It is also the far end of the implication chain: a `templates:design`-only
 * token must both SEE and be able to CALL this one, because design ⇒ read.
 */
#[McpToolScope(McpScope::TemplatesRead)]
final class ScopeReadProbeTool
{
    /**
     * Reports that a read-scoped tool ran. Test fixture only.
     */
    #[McpTool(name: 'scope_read_probe')]
    public function probe(): string
    {
        return 'read-ok';
    }
}
