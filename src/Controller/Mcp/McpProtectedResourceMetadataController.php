<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Mcp;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpTokenAuthenticator;

/**
 * RFC 9728 protected-resource metadata for the MCP server — the document the
 * 401 challenge from {@see McpTokenAuthenticator} points at, and the first thing
 * an MCP client fetches when it is told it needs a token.
 *
 * ## Everything here is DERIVED, nothing is spelled out
 *
 * Three independent facts could rot if this file restated them, so none of them
 * is restated:
 *
 * - **The path** is {@see McpTokenAuthenticator::RESOURCE_METADATA_PATH}, the
 *   very constant the challenge is built from — the URL advertised in the 401
 *   and the URL that actually answers cannot drift apart.
 * - **`resource`** is generated from the router (`_mcp_endpoint`), not
 *   concatenated from a path string, because the MCP endpoint's path lives in
 *   `config/packages/mcp.php` (`mcp.http.path`) and the bundle's RouteLoader is
 *   the only honest source for it. RFC 9728 §3.3 requires this value to be the
 *   canonical URL of the resource the client is talking to, so a stale `/…`
 *   literal here would make a spec-compliant client refuse the token.
 * - **`scopes_supported`** is {@see McpScope::cases()}. A hand-listed array
 *   would go stale the moment a scope case is added — silently, since nothing
 *   would fail.
 *
 * ## Why the host comes from the request
 *
 * `resource` and `authorization_servers` must be absolute, and the same code has
 * to be right on `http://localhost:8080` (docker compose, the test suite) and on
 * `https://wboost.cz`. Hard-coding production would break local development and
 * every functional test; deriving from the live request is correct on both and
 * is exactly what the authenticator already does for the challenge URL.
 *
 * `authorization_servers` points at OURSELVES (the site root): personal access
 * tokens are the transport today and the token endpoint is Stage 8's job, but
 * the issuer identifier is the same host either way — so clients get a
 * correct pointer now, and no consumer-visible change is needed when the OAuth
 * server lands behind it.
 *
 * The route is PUBLIC: `config/packages/security.php` carries an explicit
 * `PUBLIC_ACCESS` access_control rule for this path above the `^/` catch-all,
 * because a client reads this document precisely when it has no credentials.
 */
final class McpProtectedResourceMetadataController extends AbstractController
{
    /**
     * The MCP endpoint's route, registered by the bundle's `RouteLoader` at
     * `mcp.http.path`. The bundle exposes no constant for the name, so this is
     * the one string that has to be copied — `debug:router` shows it, and the
     * functional test fails loudly if it ever stops resolving.
     */
    private const string MCP_ENDPOINT_ROUTE = '_mcp_endpoint';

    /**
     * The document is static per host — its only inputs are the request host
     * and the scope enum, both of which change by deploy — and a client
     * refetches it every time it hits a 401, so an hour of freshness is free.
     *
     * Deliberately PRIVATE (client cache) rather than `public`: this path sits
     * under the stateful `main` firewall, whose session listener rewrites the
     * cache headers of any response that touched the session — and it zeroes
     * `max-age` outright when it sees a `public` directive
     * ({@see \Symfony\Component\HttpKernel\EventListener\AbstractSessionListener}).
     * A `public` header here would therefore be silently stomped to
     * `max-age=0`, and suppressing that rewrite to force shared caching on a
     * response that may carry `Set-Cookie` is not a trade worth making for a
     * document this cheap.
     */
    private const int CACHE_MAX_AGE = 3600;

    #[Route(
        path: McpTokenAuthenticator::RESOURCE_METADATA_PATH,
        name: 'mcp_protected_resource_metadata',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $response = new JsonResponse($this->metadata($request));

        $response->setMaxAge(self::CACHE_MAX_AGE);

        return $response;
    }

    /**
     * @return array{
     *     resource: string,
     *     authorization_servers: list<string>,
     *     scopes_supported: list<string>,
     *     bearer_methods_supported: list<string>,
     * }
     */
    private function metadata(Request $request): array
    {
        return [
            'resource' => $this->generateUrl(self::MCP_ENDPOINT_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL),
            'authorization_servers' => [$request->getSchemeAndHttpHost()],
            'scopes_supported' => array_map(
                static fn (McpScope $scope): string => $scope->value,
                McpScope::cases(),
            ),

            // The token rides in `Authorization: Bearer …` and nowhere else —
            // the authenticator reads that header only, so advertising the
            // form-body or query-parameter methods of RFC 6750 would be a lie
            // (and the query form leaks tokens into access logs).
            'bearer_methods_supported' => ['header'],
        ];
    }
}
