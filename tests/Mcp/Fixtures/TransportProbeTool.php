<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Fixtures;

use Mcp\Capability\Attribute\McpTool;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;

/**
 * A do-nothing MCP tool that exists only in the `test` environment (registered
 * from `config/services_test.php`), so `tests/Mcp/TransportTest.php` can drive a
 * real, SUCCESSFUL `tools/call` through the transport before Stage 2 ships any
 * production tool. Calling a non-existent tool would only ever exercise the
 * JSON-RPC error path.
 *
 * It is deliberately trivial and deliberately named: if it ever shows up in a
 * `tools/list` assertion, that is a test-env artefact, not a production tool.
 *
 * The scope is the cheapest one that exists: without an `#[McpToolScope]` the
 * tool would be denied to everyone (S1-T6 fails closed) and the transport test
 * would be exercising the refusal path instead of the happy one.
 */
#[McpToolScope(McpScope::TemplatesRead)]
final class TransportProbeTool
{
    /**
     * Echoes the given value back. Test fixture only.
     */
    #[McpTool(name: 'transport_probe')]
    public function probe(string $value): string
    {
        return $value;
    }
}
