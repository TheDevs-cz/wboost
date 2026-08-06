<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\OAuth2;

use JsonException;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Exceptions\ClientRegistrationFailed;
use WBoost\Web\Services\OAuth2\DynamicClientRegistrar;

/**
 * RFC 7591 Dynamic Client Registration (S8-T4) — the endpoint that lets a
 * claude.ai / ChatGPT connector introduce itself, so a user can paste
 * `https://wboost.cz/_mcp` into their client and be connected without an
 * operator ever running `app:oauth-client:create`.
 *
 * ## It is OFF unless `OAUTH2_DYNAMIC_CLIENT_REGISTRATION` says otherwise
 *
 * And it must stay off until the consent screen (S8-T5) exists. The reasoning
 * is in {@see DynamicClientRegistrar}'s docblock and it is not about this
 * code: open registration plus today's auto-approving
 * {@see \WBoost\Web\Services\OAuth2\ApproveAuthorizationRequestListener} is a
 * one-click account takeover, and neither PKCE nor https-only redirect URIs nor
 * rate limiting closes it. The flag is deliberately ONE switch that flips both
 * this endpoint and its advertisement in
 * {@see AuthorizationServerMetadataController} — a discoverable endpoint that
 * 404s, or a working endpoint nothing advertises, are both worse than either
 * consistent state.
 *
 * A disabled endpoint answers **404**, not 403: RFC 8414 says an absent
 * `registration_endpoint` means "not supported", and 404 is what a client that
 * probes the conventional path anyway should see for the same reason.
 *
 * ## Shape
 *
 * The request body is JSON client metadata (RFC 7591 §2) and the response is
 * **201** with the registered metadata echoed (§3.2.1) — no `client_secret`,
 * because only public clients are registered. Failures are §3.2.2: `400` with
 * `{error, error_description}` drawn from the RFC's own vocabulary, which is
 * what lets a client tell "fix your redirect URI" from "fix your metadata".
 *
 * `Cache-Control: no-store` on both, per §3.2.
 */
final class ClientRegistrationController extends AbstractController
{
    /**
     * Sits with `/api/authorize` and `/api/token` rather than at the root: it
     * is the same authorization server, it is carved out of the `api`
     * firewall's authentication in exactly the way `/api/token` is, and the
     * RFC 8414 metadata advertises it by GENERATED url, so the path is free to
     * be whatever is consistent here.
     */
    public const string REGISTRATION_PATH = '/api/register';

    /**
     * Deep enough for the RFC's nested `*_localized` metadata, shallow enough
     * that a hostile body cannot cost anything to parse.
     */
    private const int MAX_JSON_DEPTH = 16;

    public function __construct(
        private readonly DynamicClientRegistrar $registrar,
        private readonly bool $dynamicClientRegistrationEnabled,
    ) {
    }

    /**
     * The limiter is per client IP (the attribute's default key is
     * IP + method + path) and it is not a security boundary — an attacker with
     * addresses to spare walks around it. It is there so that an unauthenticated
     * endpoint that INSERTs a row cannot be used to fill the table, which is
     * the one abuse that survives even after consent lands.
     */
    #[Route(
        path: self::REGISTRATION_PATH,
        name: 'oauth2_client_registration',
        methods: ['POST'],
    )]
    #[RateLimit('oauth2_client_registration')]
    public function __invoke(Request $request): Response
    {
        if ($this->dynamicClientRegistrationEnabled === false) {
            throw $this->createNotFoundException('Dynamic client registration is not enabled on this server.');
        }

        try {
            $client = $this->registrar->register($this->decode($request));
        } catch (ClientRegistrationFailed $failure) {
            return $this->noStore(new JsonResponse([
                'error' => $failure->error,
                'error_description' => $failure->getMessage(),
            ], Response::HTTP_BAD_REQUEST));
        }

        return $this->noStore(new JsonResponse(
            $this->registeredMetadata($client),
            Response::HTTP_CREATED,
        ));
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ClientRegistrationFailed
     */
    private function decode(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent(), true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw ClientRegistrationFailed::invalidClientMetadata(
                'The request body is not valid JSON: ' . $exception->getMessage(),
            );
        }

        if (is_array($payload) === false) {
            throw ClientRegistrationFailed::invalidClientMetadata(
                'The request body must be a JSON object of client metadata.',
            );
        }

        return $payload;
    }

    /**
     * RFC 7591 §3.2.1: "the authorization server MUST return all registered
     * metadata about this client". Every value is read back off the SAVED
     * client rather than echoed from the request, so a caller is told what it
     * actually got — which matters, because the registrar substitutes values it
     * will not honour (grants, auth method) instead of failing.
     *
     * `client_secret` is absent, and absent is the contract: RFC 7591 requires
     * `client_secret_expires_at` whenever a secret is issued, so returning
     * neither is how a caller learns it is a public client and must use PKCE.
     *
     * @return array{
     *     client_id: string,
     *     client_id_issued_at: int,
     *     client_name: string,
     *     redirect_uris: list<string>,
     *     grant_types: list<string>,
     *     response_types: list<string>,
     *     token_endpoint_auth_method: string,
     *     scope: string,
     * }
     */
    private function registeredMetadata(Client $client): array
    {
        return [
            'client_id' => $client->getIdentifier(),
            'client_id_issued_at' => time(),
            'client_name' => $client->getName(),
            'redirect_uris' => array_map(strval(...), $client->getRedirectUris()),
            'grant_types' => array_map(strval(...), $client->getGrants()),
            'response_types' => DynamicClientRegistrar::grantedResponseTypes(),
            'token_endpoint_auth_method' => DynamicClientRegistrar::tokenEndpointAuthMethod(),
            'scope' => implode(' ', array_map(strval(...), $client->getScopes())),
        ];
    }

    /**
     * RFC 7591 §3.2 requires `no-store` on the registration response — it
     * carries a credential-shaped identifier and, for confidential clients,
     * would carry the secret itself.
     */
    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
