<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Fixtures;

use Mcp\Capability\Attribute\McpTool;

/**
 * A do-nothing MCP tool that exists only in the `test` environment (registered
 * from `config/services_test.php`), so `tests/Mcp/TransportTest.php` can drive a
 * real, SUCCESSFUL `tools/call` through the transport before Stage 2 ships any
 * production tool. Calling a non-existent tool would only ever exercise the
 * JSON-RPC error path.
 *
 * It is deliberately trivial and deliberately named: if it ever shows up in a
 * `tools/list` assertion, that is a test-env artefact, not a production tool.
 */
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
