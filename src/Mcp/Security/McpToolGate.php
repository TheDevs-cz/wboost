<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use WBoost\Web\DependencyInjection\McpToolScopePass;

/**
 * The single answer to "may the token behind THIS request call the tool named
 * `x`?" — consulted by both halves of the gate, so they cannot disagree:
 *
 * - {@see ScopeFilteredToolRegistry} asks it to build `tools/list` and to hand
 *   out (or withhold) a tool reference for `tools/call`;
 * - {@see ScopeGuardedMcpController} asks it BEFORE the MCP machinery runs, to
 *   turn a forbidden call into a real HTTP 403.
 *
 * Filtering a listing is not security — a client can call a tool it was never
 * shown — which is why the decision lives HERE rather than inside the filter,
 * and why the 403 path never consults the listing.
 *
 * ## Fail closed, three ways
 *
 * - No security token / a non-MCP request → {@see McpScopeChecker} grants
 *   nothing → every scoped tool is denied.
 * - A tool that declares no scope (a `null` entry in the compiled map) is
 *   denied to EVERYONE, including a token holding every scope. There is no
 *   "default scope" to forget to override.
 * - An unparseable scope string in the map (impossible today — the compiler
 *   pass writes enum values) degrades to "no scope declared", i.e. denied.
 *
 * ## Unknown names are NOT denied, and that is deliberate
 *
 * {@see isTool()} separates "a registered tool" from "a name nobody registered".
 * The gate only judges the former; an unknown name is passed through so the SDK
 * can answer with its honest `method not found`. Agents hallucinate tool names
 * constantly, and answering those with a 403 "insufficient scope" would send a
 * client hunting for a permission that does not exist.
 *
 * ## Not a permission model
 *
 * A granted scope means the TOKEN may reach the tool. What the acting user may
 * then see or change is still the voters' decision inside the tool — effective
 * permission = role ∩ scope.
 */
final readonly class McpToolGate
{
    /**
     * Every registered tool name → the scope it requires, or null when it
     * declares none. A name that is ABSENT is not a tool at all.
     *
     * @var array<string, null|McpScope>
     */
    private array $toolScopes;

    /**
     * The parameter is `array<string, string|null>` by construction
     * ({@see McpToolScopePass}), but a container parameter arrives as raw data,
     * so the shape is re-established here rather than assumed — a malformed
     * entry becomes "no scope declared", which denies.
     *
     * @param array<array-key, mixed> $toolScopes
     */
    public function __construct(
        #[Autowire(param: McpToolScopePass::PARAMETER)]
        array $toolScopes,
        private McpScopeChecker $scopeChecker,
    ) {
        /** @var array<string, null|McpScope> $parsed */
        $parsed = [];

        foreach ($toolScopes as $name => $scope) {
            if (!is_string($name)) {
                continue;
            }

            $parsed[$name] = is_string($scope) ? McpScope::tryFrom($scope) : null;
        }

        $this->toolScopes = $parsed;
    }

    /**
     * Is `$name` a tool this application registered at all? Says nothing about
     * permission — see the class docblock for why the two questions are
     * separate.
     */
    public function isTool(string $name): bool
    {
        return array_key_exists($name, $this->toolScopes);
    }

    /**
     * The scope a caller needs to reach `$name`, or null when the tool declares
     * none (in which case nothing unlocks it) or is not a tool at all.
     *
     * Used to name the missing permission in the `WWW-Authenticate` challenge,
     * so the agent can tell its user which scope to add to the token.
     */
    public function requiredScope(string $name): null|McpScope
    {
        return $this->toolScopes[$name] ?? null;
    }

    /**
     * The decision. False for an unknown name too — callers that want the
     * SDK's "method not found" instead of a refusal must gate on
     * {@see isTool()} first.
     */
    public function allows(string $name): bool
    {
        $required = $this->requiredScope($name);

        if ($required === null) {
            return false;
        }

        return $this->scopeChecker->granted($required);
    }
}
