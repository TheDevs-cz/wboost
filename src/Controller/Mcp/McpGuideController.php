<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Mcp;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Services\Mcp\DescribeMcpCapabilities;
use WBoost\Web\Services\OAuth2\DescribeConsentScopes;

/**
 * "AI (MCP server)" — the in-app, Czech guide to connecting an AI assistant to
 * this WBoost account.
 *
 * ## Who may see it
 *
 * Every signed-in user, with no role check. The MCP tools are gated by the
 * ordinary voters — an agent can never reach further than the person it acts
 * for — so the page is useful across the whole role range: a read-only user
 * shared into one project gets browsing, previews and exports out of it, while
 * the design tools simply refuse for them (with a message saying so). Gating
 * the page on `ROLE_DESIGNER` would hide the connection instructions from
 * exactly the people for whom "ask the assistant to export it" replaces the
 * most clicking.
 *
 * Authentication itself is the `^/` catch-all in
 * `config/packages/security.php`, the same way {@see \WBoost\Web\Controller\User\ConnectedAppsController}
 * gets it: an anonymous visitor is redirected to the Czech login form.
 *
 * ## Nothing on the page is spelled out that can be derived
 *
 * The page states facts about a running server, so every one of them comes from
 * that server rather than from prose someone has to remember to update:
 *
 * - **the endpoint URL** is generated from the router (`_mcp_endpoint`), whose
 *   path lives in `config/packages/mcp.php` — and generating it is also what
 *   makes the page correct on `http://localhost:8080` and on
 *   `https://wboost.cz` alike, which a hard-coded production URL would not be;
 * - **the permissions** are {@see McpScope} described by
 *   {@see DescribeConsentScopes}, i.e. the very wording the consent screen
 *   shows — two translations of one permission would drift, and the one on the
 *   consent screen is the one the user legally agreed to;
 * - **the capabilities** are the REGISTERED tools ({@see DescribeMcpCapabilities});
 * - **whether a client can register itself** is the same flag that decides
 *   whether `POST /api/register` answers at all, so the page cannot promise a
 *   zero-configuration connection on a deployment where it is switched off.
 */
final class McpGuideController extends AbstractController
{
    /**
     * The MCP endpoint's route, registered by the bundle's `RouteLoader` at
     * `mcp.http.path` — copied from
     * {@see McpProtectedResourceMetadataController}, which explains why the
     * name is a literal (the bundle exposes no constant for it).
     */
    private const string MCP_ENDPOINT_ROUTE = '_mcp_endpoint';

    public function __construct(
        private readonly DescribeConsentScopes $describeConsentScopes,
        private readonly DescribeMcpCapabilities $describeCapabilities,
        private readonly bool $dynamicClientRegistrationEnabled,
    ) {
    }

    #[Route(path: '/ai', name: 'mcp_guide', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('mcp_guide.html.twig', [
            'mcpUrl' => $this->generateUrl(self::MCP_ENDPOINT_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL),
            'metadataUrl' => $this->generateUrl(
                'mcp_protected_resource_metadata',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),

            // Every scope this release knows, in declaration order, described
            // once — `describeGranted()` rather than `describe()` because the
            // page lists the permissions as a catalogue, not as one client's
            // request, so the "je součástí oprávnění …" annotation would have
            // nothing to point at.
            'scopes' => $this->describeConsentScopes->describeGranted(McpScope::values()),
            'capabilities' => $this->describeCapabilities->describe(),
            'selfRegistrationEnabled' => $this->dynamicClientRegistrationEnabled,
        ]);
    }
}
