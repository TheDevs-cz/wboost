<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Fixtures;

use Mcp\Capability\Attribute\McpTool;

/**
 * A registered MCP tool that declares NO scope — the "somebody forgot the
 * attribute" case, deliberately kept alive in the test environment.
 *
 * Stage 2+ adds roughly fourteen tools, and one missing `#[McpToolScope]` must
 * not quietly become the single tool every token can reach. So this fixture is
 * the executable statement of the fail-closed rule: it is invisible in
 * `tools/list` even to a token holding EVERY scope, and calling it is refused.
 * Its failure mode is "the tool does not work for anyone", which surfaces the
 * first time the tool is tried — not "the tool works for everyone", which
 * surfaces in an incident report.
 *
 * Test environment only (registered from `config/services_test.php`).
 */
final class UnscopedProbeTool
{
    /**
     * Reports that an unscoped tool ran — which must never happen. Test fixture
     * only.
     */
    #[McpTool(name: 'unscoped_probe')]
    public function probe(): string
    {
        return 'unscoped-ok';
    }
}
