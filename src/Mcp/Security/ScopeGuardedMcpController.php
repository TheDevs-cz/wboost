<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Security;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Mcp\Transport\BufferedMcpController;

/**
 * Refuses a `tools/call` the token's scopes do not cover with a real HTTP
 * **403** and an RFC 6750 `WWW-Authenticate: … error="insufficient_scope"`
 * challenge, BEFORE the MCP machinery gets the message.
 *
 * ## Why the refusal has to happen out here
 *
 * A 403 is the answer the MCP authorization spec and every OAuth-aware client
 * expect, and it cannot be produced from inside the SDK: `Mcp\Server\Protocol`
 * wraps each handler in a Fiber inside `catch (\Throwable)`, turning ANY
 * exception into a JSON-RPC `internal error` at HTTP 200. There is no hook that
 * lets a handler set a status code either — the transport only ever gets a
 * status from `Protocol`'s own session errors. So the choice is: answer at the
 * HTTP layer, or give up on 403 and hide the refusal inside a 200. This class
 * is the first option, and it costs one `json_decode` of a body the transport
 * was about to decode anyway.
 *
 * ## Position in the decoration chain
 *
 * `mcp.server.controller` is decorated twice:
 *
 *     ScopeGuardedMcpController (priority -10, outermost)
 *       └── BufferedMcpController (priority 0)
 *             └── the bundle's McpController
 *
 * Lower `AsDecorator` priority = further from the original service, so the
 * negative priority is what puts the gate on the outside — a forbidden call
 * never reaches the transport, never opens a Fiber, never touches a tool
 * service. The `$inner` type-hint pins that order: slipping another decorator
 * in between fails loudly instead of silently changing what this class wraps.
 *
 * ## Scope of the refusal
 *
 * ONLY `tools/call` is inspected. `tools/list` needs no gate — it is FILTERED
 * ({@see ScopeFilteredToolRegistry}), and a filtered listing is not a security
 * boundary, which is exactly why this gate exists independently of it and never
 * consults it. A name nobody registered is passed through so the SDK can answer
 * its honest `method not found`; see {@see McpToolGate}.
 *
 * A JSON-RPC BATCH is judged as a whole: one forbidden call in the array
 * refuses the whole request. Splitting a batch would mean answering 403 and 200
 * to the same HTTP request, which HTTP cannot express — and a client that is
 * told "insufficient scope" for the batch can retry without the offending call.
 */
#[AsDecorator('mcp.server.controller', priority: -10)]
final readonly class ScopeGuardedMcpController
{
    private const string TOOL_CALL_METHOD = 'tools/call';

    public function __construct(
        #[AutowireDecorated]
        private BufferedMcpController $inner,
        private McpToolGate $gate,
        private McpChallenge $challenge,
    ) {
    }

    public function handle(Request $request): Response
    {
        return $this->refusal($request) ?? $this->inner->handle($request);
    }

    /**
     * The 403 for the first forbidden tool call in the body, or null when the
     * request may proceed.
     */
    private function refusal(Request $request): null|Response
    {
        foreach ($this->calledToolNames($request) as $name) {
            if (!$this->gate->isTool($name) || $this->gate->allows($name)) {
                continue;
            }

            $required = $this->gate->requiredScope($name);

            return $this->challenge->insufficientScope(
                $request,
                $required,
                $required === null
                    // Only reachable when a tool class forgot #[McpToolScope]:
                    // no scope unlocks it, so saying "add a scope" would be a
                    // lie. Denied for everyone is the fail-closed design.
                    ? sprintf('The tool "%s" declares no MCP scope and cannot be called.', $name)
                    : sprintf('The tool "%s" requires the "%s" scope, which this token does not carry.', $name, $required->value),
            );
        }

        return null;
    }

    /**
     * Every tool name the body asks to call — none for anything that is not a
     * POSTed `tools/call`.
     *
     * Reading the body here is safe for the inner controller: `getContent()`
     * caches the string on the Request, and the PSR-7 bridge's later
     * `getContent(true)` re-wraps that cached string in a fresh stream.
     * A body that does not parse is left alone entirely — the SDK owns the
     * parse-error response, and inventing a second one here would answer
     * malformed JSON with 403.
     *
     * @return list<string>
     */
    private function calledToolNames(Request $request): array
    {
        if (!$request->isMethod(Request::METHOD_POST)) {
            return [];
        }

        $decoded = json_decode($request->getContent(), true);

        if (!is_array($decoded)) {
            return [];
        }

        // A batch is a JSON array of messages; a single call is a JSON object.
        $messages = array_is_list($decoded) ? $decoded : [$decoded];

        /** @var list<string> $names */
        $names = [];

        foreach ($messages as $message) {
            if (!is_array($message) || ($message['method'] ?? null) !== self::TOOL_CALL_METHOD) {
                continue;
            }

            $params = $message['params'] ?? null;
            $name = is_array($params) ? $params['name'] ?? null : null;

            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
