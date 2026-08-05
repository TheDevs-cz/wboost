<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ONE place `/_mcp` formats an OAuth failure — both the `WWW-Authenticate`
 * header and the JSON body that goes with it.
 *
 * Extracted from {@see McpTokenAuthenticator} when S1-T6 added a second emitter
 * ({@see ScopeGuardedMcpController}'s 403). Two implementations of a header
 * whose exact grammar a client parses is precisely the kind of duplication that
 * drifts — one of them gains `resource_metadata`, the other does not, and the
 * bug only shows up in somebody else's client.
 *
 * ## The two shapes
 *
 * - **401** — the request could not be authenticated. Per RFC 6750 §3.1 the
 *   `error` code is OMITTED when nothing was presented (an error code implies a
 *   request that actually tried) and is `invalid_token` when a `wb_mcp_` token
 *   was presented but did not resolve. The advertised `scope` is the MINIMUM to
 *   get in at all.
 * - **403** — authentication succeeded, the token's scopes did not cover the
 *   tool. `error="insufficient_scope"` and the advertised `scope` is the one
 *   that was REQUIRED, so the agent can tell its user exactly which scope to
 *   add to the token rather than guessing.
 *
 * Both carry `resource_metadata` (RFC 9728 §5.1), so a client that meets either
 * one knows where the token comes from without special-casing the status.
 */
final readonly class McpChallenge
{
    /**
     * 401. `$error` null = nothing usable was presented.
     */
    public function unauthorized(Request $request, null|string $error, string $description): JsonResponse
    {
        return $this->challenge(
            $request,
            Response::HTTP_UNAUTHORIZED,
            $error,
            $description,
            // The floor: every MCP token carries at least this much, so it is
            // the honest thing to ask an unauthenticated client to obtain.
            McpScope::TemplatesRead,
        );
    }

    /**
     * 403. `$required` is the scope the call needed — null when the tool
     * declares no scope at all, in which case NO scope would have helped and
     * advertising one would be a lie.
     */
    public function insufficientScope(Request $request, null|McpScope $required, string $description): JsonResponse
    {
        return $this->challenge($request, Response::HTTP_FORBIDDEN, 'insufficient_scope', $description, $required);
    }

    /**
     * RFC 6750 §3 / RFC 9728 §5.1: `Bearer` followed by comma-separated
     * quoted-string parameters. The body repeats the machine-readable parts so
     * a client that only reads bodies still gets them.
     */
    private function challenge(
        Request $request,
        int $status,
        null|string $error,
        string $description,
        null|McpScope $scope,
    ): JsonResponse {
        $parameters = [];
        $body = [
            'error' => $error ?? 'unauthorized',
            'error_description' => $description,
        ];

        if ($error !== null) {
            $parameters[] = sprintf('error="%s"', $error);
        }

        $parameters[] = sprintf('resource_metadata="%s"', $this->resourceMetadataUrl($request));

        if ($scope !== null) {
            $parameters[] = sprintf('scope="%s"', $scope->value);
            $body['scope'] = $scope->value;
        }

        return new JsonResponse(
            $body,
            $status,
            ['WWW-Authenticate' => 'Bearer ' . implode(', ', $parameters)],
        );
    }

    /**
     * Built from the live request, not from a configured host: the same code has
     * to emit `http://localhost:8080/...` for local docker compose and
     * `https://wboost.cz/...` in production. `getSchemeAndHttpHost()` rather
     * than `getUriForPath()` on purpose — a `.well-known` URI is defined
     * relative to the HOST root (RFC 8615) and must not inherit a base path.
     */
    private function resourceMetadataUrl(Request $request): string
    {
        return $request->getSchemeAndHttpHost() . McpTokenAuthenticator::RESOURCE_METADATA_PATH;
    }
}
