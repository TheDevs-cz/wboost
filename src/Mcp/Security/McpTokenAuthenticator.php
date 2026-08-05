<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Security;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use WBoost\Web\Entity\McpAccessToken;
use WBoost\Web\Entity\User;
use WBoost\Web\Repository\McpAccessTokenRepository;

/**
 * Authenticates the MCP server (`/_mcp`) with a personal access token presented
 * as `Authorization: Bearer wb_mcp_…`.
 *
 * It resolves a real {@see User}, so every voter downstream keeps deciding what
 * that user may touch; the token's `scopes` are stashed on the security token
 * as a SECOND, narrowing axis for {@see McpScopeChecker} (effective permission
 * = role ∩ scope). Nothing here gates tools — that is S1-T6's job.
 *
 * ## OAuth-shaped failures
 *
 * Personal access tokens are the transport today, full OAuth 2.1 is Stage 8 —
 * but the 401 already speaks the protocol the MCP spec expects, so a client can
 * discover where to get a token without this class changing later:
 *
 *     WWW-Authenticate: Bearer resource_metadata="https://…/.well-known/oauth-protected-resource", scope="templates:read"
 *
 * Two shapes, per RFC 6750 §3.1: a request that presented NO usable credentials
 * gets the bare challenge (no `error=`), a request whose `wb_mcp_` token did not
 * resolve gets `error="invalid_token"`. Both are 401 — never the 302 to
 * `/login` that `main`'s `form_login` entry point would produce, which is why
 * this authenticator is also the `mcp` firewall's entry point.
 *
 * ## `supports()` is the cheap filter
 *
 * A missing header, a malformed one, or a bearer token belonging to some other
 * scheme (the OAuth API's JWT, say) all fail {@see McpTokenGenerator::looksLikeToken()}
 * and return `false` — no database round-trip, and the entry point answers with
 * the bare challenge.
 */
final class McpTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    /**
     * RFC 9728 resource metadata, served by S1-T4. The authenticator only needs
     * the PATH — the absolute URL is derived per request (see
     * {@see resourceMetadataUrl()}), so the challenge is correct on
     * `localhost:8080` and on `https://wboost.cz` without a configured base URL.
     */
    public const string RESOURCE_METADATA_PATH = '/.well-known/oauth-protected-resource';

    /**
     * How stale `lastUsedAt` must be before authentication writes it again. An
     * agent makes many tool calls a minute and every one of them authenticates;
     * writing on each would put a DB write on the hot path for a column nobody
     * reads more precisely than "roughly when was this token last seen".
     */
    private const string LAST_USED_THROTTLE = '-1 minute';

    /** Carries the resolved row from {@see authenticate()} to {@see createToken()}. */
    private const string PASSPORT_ACCESS_TOKEN = 'mcp_access_token';

    /** The single authentication instant, so lookup and touch agree. */
    private const string PASSPORT_AUTHENTICATED_AT = 'mcp_authenticated_at';

    public function __construct(
        private readonly McpAccessTokenRepository $accessTokens,
        private readonly McpTokenGenerator $tokenGenerator,
        private readonly ClockInterface $clock,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $this->presentedToken($request) !== null;
    }

    public function authenticate(Request $request): Passport
    {
        $presented = $this->presentedToken($request);

        // Unreachable via the firewall (supports() gates it) — but authenticate()
        // is public API, so it states the precondition instead of assuming it.
        if ($presented === null) {
            throw new BadCredentialsException('No MCP access token was presented.');
        }

        $now = $this->clock->now();
        $accessToken = $this->accessTokens->findActiveByHash($this->tokenGenerator->hash($presented), $now);

        if ($accessToken === null) {
            throw new BadCredentialsException('The MCP access token is unknown, expired or revoked.');
        }

        $user = $accessToken->user;

        // The identifier matches `api_user_provider` (entity User, property id),
        // so a refresh would find the same row; the loader means the provider is
        // not queried on the happy path.
        $passport = new SelfValidatingPassport(
            new UserBadge($user->id->toString(), static fn (): User => $user),
        );

        $passport->setAttribute(self::PASSPORT_ACCESS_TOKEN, $accessToken);
        $passport->setAttribute(self::PASSPORT_AUTHENTICATED_AT, $now);

        return $passport;
    }

    /**
     * Runs AFTER `CheckPassportEvent` — i.e. after the firewall's `UserChecker`
     * has rejected an unconfirmed account — which is why `lastUsedAt` is touched
     * from here and not from {@see authenticate()}: a token whose user is
     * blocked never records a use.
     */
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $accessToken = $passport->getAttribute(self::PASSPORT_ACCESS_TOKEN);
        $authenticatedAt = $passport->getAttribute(self::PASSPORT_AUTHENTICATED_AT);

        if (!$accessToken instanceof McpAccessToken || !$authenticatedAt instanceof DateTimeImmutable) {
            throw new BadCredentialsException('The MCP passport lost its access token.');
        }

        $token = parent::createToken($passport, $firewallName);

        // THE SEAM (S1-T2): raw wire strings, verbatim off the entity — never
        // McpScope instances, so the security token stays trivially
        // serializable and McpScopeChecker owns every parsing decision.
        $token->setAttribute(McpScopeChecker::TOKEN_ATTRIBUTE, $accessToken->scopes);

        $this->touchLastUsed($accessToken, $authenticatedAt);

        return $token;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): null|Response
    {
        // Null = the request continues to the MCP controller.
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->challenge($request, 'invalid_token', 'The MCP access token is invalid, expired or revoked.');
    }

    /**
     * Entry point: no usable credentials at all. Named in the `mcp` firewall so
     * `main`'s `form_login` entry point can never turn this into a 302.
     */
    public function start(Request $request, null|AuthenticationException $authException = null): Response
    {
        return $this->challenge(
            $request,
            null,
            'An MCP access token is required: Authorization: Bearer ' . McpTokenGenerator::TOKEN_PREFIX . '…',
        );
    }

    /**
     * `Authorization: Bearer <token>` — but only when `<token>` is one of ours.
     * Anything else is not this firewall's business and must not cost a query.
     */
    private function presentedToken(Request $request): null|string
    {
        $header = $request->headers->get('Authorization');

        if ($header === null) {
            return null;
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) !== 1) {
            return null;
        }

        $presented = $matches[1];

        return $this->tokenGenerator->looksLikeToken($presented) ? $presented : null;
    }

    private function touchLastUsed(McpAccessToken $accessToken, DateTimeImmutable $now): void
    {
        $lastUsedAt = $accessToken->lastUsedAt;

        if ($lastUsedAt !== null && $lastUsedAt > $now->modify(self::LAST_USED_THROTTLE)) {
            return;
        }

        $this->accessTokens->touchLastUsed($accessToken, $now);
    }

    /**
     * RFC 9728 §5.1 challenge: quoted values, comma-separated. `error` is
     * omitted when nothing was presented (RFC 6750 §3.1 — an error code implies
     * a request that actually tried).
     */
    private function challenge(Request $request, null|string $error, string $description): Response
    {
        $parameters = [];

        if ($error !== null) {
            $parameters[] = sprintf('error="%s"', $error);
        }

        $parameters[] = sprintf('resource_metadata="%s"', $this->resourceMetadataUrl($request));
        $parameters[] = sprintf('scope="%s"', McpScope::TemplatesRead->value);

        return new JsonResponse(
            [
                'error' => $error ?? 'unauthorized',
                'error_description' => $description,
            ],
            Response::HTTP_UNAUTHORIZED,
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
        return $request->getSchemeAndHttpHost() . self::RESOURCE_METADATA_PATH;
    }
}
