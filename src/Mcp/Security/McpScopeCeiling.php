<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Security;

use WBoost\Web\Entity\User;

/**
 * Caps a list of raw scope strings to what an admin allowed the user
 * ({@see User::allowedMcpScopes()}) — the ONE implementation behind all three
 * places an OAuth grant is narrowed:
 *
 * - the consent screen, so the user is only ever asked about what they can get
 *   ({@see \WBoost\Web\Services\OAuth2\ResolveAuthorizationRequestListener});
 * - the issued token ({@see \WBoost\Web\Services\OAuth2\CapScopesToUserCeilingListener});
 * - every MCP request made with such a token ({@see McpOAuthAuthenticator}), so
 *   an admin narrowing the ceiling does not wait for the token to expire.
 *
 * Only MCP scopes are capped. Anything else passes through untouched — the REST
 * API's `api` scope never reaches a user-bound grant, and unknown values are
 * the consent screen's to flag, not this class's to hide.
 */
final class McpScopeCeiling
{
    /**
     * A scope inside the ceiling is kept AS GIVEN (so the consent screen still
     * shows what it implies as implied). A scope above it is replaced by the
     * part of its implication closure ({@see McpScope::grants()}) the ceiling
     * allows: `templates:design` under a read-only ceiling leaves
     * `templates:read`, which both sides grant, rather than nothing.
     *
     * @param list<string> $scopes
     *
     * @return list<string> order preserved
     */
    public static function cap(User $user, array $scopes): array
    {
        $allowed = $user->allowedMcpScopes();

        /** @var list<string> $capped */
        $capped = [];

        foreach ($scopes as $value) {
            $scope = McpScope::tryFrom($value);
            $candidates = $scope === null || in_array($scope, $allowed, true)
                ? [$value]
                : array_map(
                    static fn (McpScope $granted): string => $granted->value,
                    array_filter($scope->grants(), static fn (McpScope $granted): bool => in_array($granted, $allowed, true)),
                );

            foreach ($candidates as $candidate) {
                if (in_array($candidate, $capped, true) === false) {
                    $capped[] = $candidate;
                }
            }
        }

        return $capped;
    }

    /**
     * True when the request named MCP scopes and the ceiling left none of them —
     * a grant that would connect the client to nothing, which is refused rather
     * than handed out empty.
     *
     * @param list<string> $requested
     * @param list<string> $capped
     */
    public static function leftNothing(array $requested, array $capped): bool
    {
        return McpScope::fromStrings($requested) !== [] && McpScope::fromStrings($capped) === [];
    }
}
