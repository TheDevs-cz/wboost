<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\OAuth2;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Mcp\Security\McpScope;

/**
 * RFC 8414 authorization-server metadata (S8-T2) — the second half of the
 * discovery chain an MCP client walks: the 401 challenge names the RFC 9728
 * protected-resource document
 * ({@see \WBoost\Web\Controller\Mcp\McpProtectedResourceMetadataController}),
 * that document names this host as its authorization server, and THIS document
 * says where to send the user and how to talk to the token endpoint.
 *
 * It exists because claude.ai and ChatGPT connectors cannot send custom headers
 * — a personal access token can never reach them — so the interactive OAuth
 * flow is the only mechanism they can use, and they will only start it if they
 * can discover it here.
 *
 * ## Everything is DERIVED, exactly like its RFC 9728 twin
 *
 * - **Endpoints** come from the ROUTER (`oauth2_authorize` / `oauth2_token`,
 *   registered by the bundle and prefixed `/api` in `config/routes/oauth2.php`).
 *   A literal `'/api/authorize'` here would silently rot the day that prefix
 *   moves, and a spec-compliant client would be sent to a 404.
 * - **`issuer` and both endpoints follow the LIVE REQUEST HOST**, so the same
 *   code is correct on `http://localhost:8080`, in the functional tests and on
 *   `https://wboost.cz` with no per-environment configuration. RFC 8414 §3.3
 *   additionally REQUIRES `issuer` to equal the URL this document was fetched
 *   from minus the well-known path — deriving it is the only way to satisfy
 *   that on every host.
 * - **`scopes_supported`** is {@see McpScope::cases()}; a hand-listed copy
 *   would go stale the moment a case is added, silently.
 * - **`grant_types_supported`** is the `oauth2.grant_types_supported`
 *   container parameter, which is the SAME list that drives the
 *   `enable_*_grant` switches in `config/packages/league_oauth2_server.php`.
 *   The bundle publishes no parameter of its own for this, so one list feeding
 *   both is what keeps the advertisement honest.
 *
 * The path is host-root-relative per RFC 8615, NOT under `/api` — and it is
 * PUBLIC via an explicit access_control rule in `config/packages/security.php`,
 * because a client reads it before it has any credentials at all.
 */
final class AuthorizationServerMetadataController extends AbstractController
{
    /**
     * Well-known URI registered for RFC 8414. Exposed as a constant so the
     * functional test cannot drift from the route it asserts against.
     */
    public const string METADATA_PATH = '/.well-known/oauth-authorization-server';

    /**
     * Same reasoning as the RFC 9728 document: static per host, refetched by a
     * client whenever discovery restarts, so an hour of freshness is free.
     *
     * Deliberately NOT `public`: this path is served by the stateful `main`
     * firewall, and
     * {@see \Symfony\Component\HttpKernel\EventListener\AbstractSessionListener}
     * rewrites `max-age` to 0 the moment it sees a `public` directive on a
     * response that touched the session — so asking for shared caching here
     * would actually DISABLE caching entirely.
     */
    private const int CACHE_MAX_AGE = 3600;

    /**
     * @param list<string> $grantTypesSupported
     */
    public function __construct(
        private readonly array $grantTypesSupported,
    ) {
    }

    #[Route(
        path: self::METADATA_PATH,
        name: 'oauth2_authorization_server_metadata',
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
     *     issuer: string,
     *     authorization_endpoint: string,
     *     token_endpoint: string,
     *     scopes_supported: list<string>,
     *     response_types_supported: list<string>,
     *     response_modes_supported: list<string>,
     *     grant_types_supported: list<string>,
     *     code_challenge_methods_supported: list<string>,
     *     token_endpoint_auth_methods_supported: list<string>,
     *     client_id_metadata_document_supported: bool,
     * }
     */
    private function metadata(Request $request): array
    {
        return [
            'issuer' => $request->getSchemeAndHttpHost(),
            'authorization_endpoint' => $this->generateUrl('oauth2_authorize', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'token_endpoint' => $this->generateUrl('oauth2_token', [], UrlGeneratorInterface::ABSOLUTE_URL),

            'scopes_supported' => McpScope::values(),

            // Only the authorization-code response type exists: the implicit
            // grant is off (OAuth 2.1 removes it), and league answers a code
            // flow on the redirect query string.
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],

            'grant_types_supported' => $this->grantTypesSupported,

            // S256 ONLY. `plain` is technically implementable by league but the
            // bundle gates it behind a per-client `allow_plain_text_pkce` flag
            // that nothing here sets, and OAuth 2.1 wants S256 anyway — so
            // advertising `plain` would offer a method every client would be
            // refused on.
            'code_challenge_methods_supported' => ['S256'],

            // What league's AbstractGrant actually accepts at the token
            // endpoint: HTTP Basic or form-encoded credentials for confidential
            // clients, and nothing at all for public ones (which is precisely
            // the case PKCE protects).
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],

            // Client ID Metadata Documents are S8-T4. Advertising `true` before
            // the server can actually dereference a URL client_id would make
            // every such client fail at the authorize step instead of falling
            // back to a registered client_id, so this stays false until the
            // fetch (with its SSRF guards) exists. `registration_endpoint`
            // (RFC 7591) is omitted for the same reason: an absent field means
            // "not supported", which is the truth today.
            'client_id_metadata_document_supported' => false,
        ];
    }
}
