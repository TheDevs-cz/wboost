<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Security;

use Symfony\Bundle\SecurityBundle\Security;

/**
 * Answers exactly one question: **does the token behind the current request
 * carry this scope?**
 *
 * It is NOT an authorisation service. Whether the acting user may see a given
 * project or edit a given variant stays with the voters
 * (`ProjectVoter`, `TemplateVariantVoter`, …) exactly as on the web; this class
 * only narrows on the second axis — effective permission = role ∩ scope. Tool
 * gating therefore always asks BOTH.
 *
 * ## The seam with the authenticator
 *
 * {@see McpTokenAuthenticator} (S1-T3) is what puts the scopes in reach: after
 * resolving the {@see \WBoost\Web\Entity\McpAccessToken} it stashes that row's
 * raw `scopes` on the security token:
 *
 *     $token->setAttribute(McpScopeChecker::TOKEN_ATTRIBUTE, $accessToken->scopes);
 *
 * The value shape is **`list<string>`** — the raw wire strings, copied verbatim
 * from the entity, NOT {@see McpScope} instances. Raw strings keep the security
 * token trivially serializable and let this side own the parsing rule (unknown
 * strings are dropped, never fatal — see {@see McpScope::fromStrings()}).
 *
 * ## Fail closed
 *
 * Any request that is not an authenticated MCP request — no security token at
 * all, a token from another firewall (a logged-in web session, an OAuth API
 * client), or an attribute of the wrong shape — grants NOTHING:
 * {@see granted()} returns `false` for every scope and {@see grantedScopes()}
 * returns an empty list. A missing attribute is treated as "no scopes", never
 * as "all scopes", so forgetting to set it can only lock tools down.
 */
final readonly class McpScopeChecker
{
    /**
     * Key under which the granted scopes live on the security token's
     * attributes. The authenticator writes it, this class reads it, and nothing
     * else should touch the string literal.
     */
    public const string TOKEN_ATTRIBUTE = 'mcp_scopes';

    public function __construct(
        private Security $security,
    ) {
    }

    /**
     * True when the current token carries `$scope`, directly or by implication
     * (a `templates:design` token IS granted `templates:read`).
     */
    public function granted(McpScope $scope): bool
    {
        return in_array($scope, $this->grantedScopes(), true);
    }

    /**
     * The full effective scope set of the current token: everything it was
     * granted, plus everything those scopes imply, de-duplicated.
     *
     * Used for `tools/list` filtering and for naming scopes in a
     * `WWW-Authenticate` challenge. Empty list on a non-MCP request.
     *
     * @return list<McpScope>
     */
    public function grantedScopes(): array
    {
        /** @var list<McpScope> $granted */
        $granted = [];

        foreach (McpScope::fromStrings($this->rawScopes()) as $scope) {
            foreach ($scope->grants() as $implied) {
                if (in_array($implied, $granted, true)) {
                    continue;
                }

                $granted[] = $implied;
            }
        }

        return $granted;
    }

    /**
     * Reads the attribute the authenticator set. Token attributes are `mixed`,
     * so the shape is checked rather than assumed — a malformed attribute
     * degrades to "no scopes" like every other failure mode here.
     *
     * @return list<string>
     */
    private function rawScopes(): array
    {
        $token = $this->security->getToken();

        if ($token === null || $token->hasAttribute(self::TOKEN_ATTRIBUTE) === false) {
            return [];
        }

        $raw = $token->getAttribute(self::TOKEN_ATTRIBUTE);

        if (is_array($raw) === false) {
            return [];
        }

        /** @var list<string> $values */
        $values = [];

        foreach ($raw as $value) {
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
