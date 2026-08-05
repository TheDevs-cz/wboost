<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Fixtures;

use Mcp\Capability\Attribute\McpTool;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;

/**
 * A `templates:design` tool. Test environment only.
 *
 * The one a `templates:read` token must neither see in `tools/list` nor be able
 * to call — and the proof that the 403 gate is independent of the filter, since
 * `tests/Mcp/ScopeFilteringTest.php` calls it by name after confirming it was
 * never advertised.
 */
#[McpToolScope(McpScope::TemplatesDesign)]
final class ScopeDesignProbeTool
{
    /**
     * Reports that a design-scoped tool ran. Test fixture only.
     */
    #[McpTool(name: 'scope_design_probe')]
    public function probe(): string
    {
        return 'design-ok';
    }
}
