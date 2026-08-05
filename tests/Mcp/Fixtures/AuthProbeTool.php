<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Fixtures;

use Mcp\Capability\Attribute\McpTool;
use Symfony\Bundle\SecurityBundle\Security;
use WBoost\Web\Entity\User;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpScopeChecker;
use WBoost\Web\Mcp\Security\McpToolScope;

/**
 * Reports WHO the current `/_mcp` request authenticated as, and with which
 * scopes. Test environment only (registered from `config/services_test.php`).
 *
 * `tests/Mcp/AuthTest.php` needs to assert identity, not just a 200 — a
 * mis-wired authenticator that resolved the wrong user would pass every
 * status-code assertion. Reading it back out of a tool is the honest way: it
 * observes the security context from INSIDE the request the authenticator
 * served, rather than inspecting a token-storage instance afterwards.
 *
 * It doubles as the proof of the S1-T2 seam — the scopes it lists come from
 * {@see McpScopeChecker}, i.e. from the attribute the authenticator stashed.
 *
 * Scoped `templates:read` because S1-T6 denies any tool that declares nothing;
 * `AuthTest` drives it with the all-scopes fixture token, so the gate is not
 * what that test is measuring.
 */
#[McpToolScope(McpScope::TemplatesRead)]
final class AuthProbeTool
{
    public function __construct(
        private readonly Security $security,
        private readonly McpScopeChecker $scopeChecker,
    ) {
    }

    /**
     * Returns "<email>|<comma-separated granted scopes>". Test fixture only.
     */
    #[McpTool(name: 'auth_probe')]
    public function probe(): string
    {
        $user = $this->security->getUser();
        $email = $user instanceof User ? $user->email : 'anonymous';

        $scopes = array_map(
            static fn (McpScope $scope): string => $scope->value,
            $this->scopeChecker->grantedScopes(),
        );

        sort($scopes);

        return $email . '|' . implode(',', $scopes);
    }
}
