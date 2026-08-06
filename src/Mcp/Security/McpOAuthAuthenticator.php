<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Security;

use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Ramsey\Uuid\Uuid;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * The SECOND credential `/_mcp` accepts (S8-T6): an OAuth 2.1 bearer token
 * issued by this application's own authorization server — the authorization-code
 * flow S8-T1/T2 built, which is the only mechanism claude.ai and ChatGPT
 * connectors can use (they cannot send the custom header a personal access token
 * would ride in).
 *
 * ## The equivalence this class exists to guarantee
 *
 * A PAT-authenticated agent and an OAuth-authenticated agent must be
 * INDISTINGUISHABLE to everything downstream. So this authenticator produces
 * exactly what {@see McpTokenAuthenticator} produces and nothing else:
 *
 * - the same security token class (`PostAuthenticationToken`, straight from
 *   {@see AbstractAuthenticator::createToken()}),
 * - carrying the same {@see \WBoost\Web\Entity\User} entity, resolved through
 *   the firewall's `api_user_provider` — so every voter keeps deciding what that
 *   user may touch, exactly as on the web,
 * - with the granted scopes under the same attribute
 *   ({@see McpScopeChecker::TOKEN_ATTRIBUTE}) in the same shape (raw
 *   `list<string>`).
 *
 * {@see McpScopeChecker}, {@see McpToolGate}, {@see ScopeFilteredToolRegistry}
 * and every tool therefore need no knowledge of how the caller authenticated,
 * and none of them changed for this task.
 *
 * ## Scope translation is DELIBERATELY not a translation
 *
 * The JWT's `scopes` claim is copied VERBATIM, exactly as the PAT path copies
 * `mcp_access_token.scopes`. There is no mapping table, because
 * {@see McpScope::fromStrings()} already owns the rule: a string this release
 * does not understand is DROPPED. That is what makes the dangerous case safe by
 * construction — a `client_credentials` token for the REST API carries the
 * legacy blanket scope `api`, which is not an {@see McpScope}, so such a token
 * authenticates but ends up with an EMPTY effective scope set: `tools/list`
 * shows nothing and every `tools/call` is refused with 403 `insufficient_scope`.
 * Fail-closed, with no special case to forget.
 *
 * ## Why not the bundle's own authenticator
 *
 * `oauth2: true` (what the `api` firewall uses) cannot be reused here:
 *
 * - `OAuth2Authenticator::supports()` is `str_starts_with($header, 'Bearer ')`,
 *   so it claims `wb_mcp_…` requests too. Symfony runs EVERY supporting
 *   authenticator in turn and does not stop at the first success (a success
 *   whose `onAuthenticationSuccess()` returns null lets the loop continue) — so
 *   the bundle's authenticator would run after a successful PAT authentication,
 *   fail to parse the PAT as a JWT, and overwrite the authenticated request with
 *   its own 401. Its factory exposes no configuration and the class is `final`,
 *   so that overlap cannot be narrowed. **Disjoint `supports()` is the whole
 *   design here**, and the `wb_mcp_` prefix is what makes it total and cheap.
 * - It builds an `OAuth2Token` with its own `scopes` attribute name and
 *   `ROLE_OAUTH2_*` roles, and substitutes a `ClientCredentialsUser` (not a
 *   {@see \WBoost\Web\Entity\User}) when the token names no user — a shape the
 *   voters downstream do not expect.
 * - Its `onAuthenticationFailure()` answers with a plain-text 401 (or rethrows),
 *   not the {@see McpChallenge} an MCP client discovers the token endpoint from.
 *
 * What IS reused is the part that matters: the bundle's {@see ResourceServer},
 * i.e. league's real signature / expiry / revocation check. No JWT is parsed by
 * hand here.
 */
final class McpOAuthAuthenticator extends AbstractAuthenticator
{
    /** Carries the token's scopes from {@see authenticate()} to {@see createToken()}. */
    private const string PASSPORT_SCOPES = 'mcp_oauth_scopes';

    public function __construct(
        private readonly ResourceServer $resourceServer,
        #[Autowire(service: 'league.oauth2_server.factory.psr_http')]
        private readonly HttpMessageFactoryInterface $httpMessageFactory,
        private readonly McpTokenGenerator $tokenGenerator,
        private readonly McpChallenge $challenge,
    ) {
    }

    /**
     * Every bearer token that is NOT a personal access token — the exact
     * complement of {@see McpTokenAuthenticator::supports()}, so the two never
     * both claim a request (see the class docblock for why that would break).
     */
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
            throw new BadCredentialsException('No OAuth access token was presented.');
        }

        try {
            $validated = $this->resourceServer->validateAuthenticatedRequest(
                $this->httpMessageFactory->createRequest($request),
            );
        } catch (OAuthServerException $exception) {
            throw new BadCredentialsException('The OAuth access token was rejected by the resource server.', 0, $exception);
        }

        $subject = $validated->getAttribute('oauth_user_id');

        // The `sub` claim is the App User UUID on BOTH grants — the auth-code
        // one via WBoost\Web\Services\OAuth2\AppUserConverter, the
        // client_credentials one via IssueAccessTokenWithUserListener.
        //
        // The UUID shape is checked BEFORE the provider is asked, and that check
        // is load-bearing rather than defensive tidiness: `api_user_provider` is
        // an entity provider on the `id` column, and Postgres rejects a
        // non-UUID literal against a `uuid` column with a DRIVER error — a 500,
        // not the 401 a client can act on. A token naming no user at all (a
        // client_credentials client with no `oauth2_client_user` row) lands here
        // too, and must not get in: with no App User there is nobody for the
        // voters to decide about.
        if (!is_string($subject) || !Uuid::isValid($subject)) {
            throw new BadCredentialsException('The OAuth access token does not identify an application user.');
        }

        // No user loader: the firewall's UserProviderListener fills in
        // `api_user_provider`, which resolves the very same row the PAT path
        // reaches through McpAccessToken::$user.
        $passport = new SelfValidatingPassport(new UserBadge($subject));

        $passport->setAttribute(self::PASSPORT_SCOPES, self::stringList($validated->getAttribute('oauth_scopes')));

        return $passport;
    }

    /**
     * Runs AFTER `CheckPassportEvent`, i.e. after the firewall's `UserChecker`
     * has rejected an unconfirmed (or deactivated) account — the same gate the
     * PAT path passes through, since it belongs to the firewall rather than to
     * either authenticator.
     */
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $token = parent::createToken($passport, $firewallName);

        // THE SEAM (S1-T2), fed identically to the PAT path: raw wire strings,
        // so McpScopeChecker owns every parsing decision — including dropping
        // the REST API's `api` scope, which is not an MCP scope.
        $token->setAttribute(
            McpScopeChecker::TOKEN_ATTRIBUTE,
            self::stringList($passport->getAttribute(self::PASSPORT_SCOPES)),
        );

        return $token;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): null|Response
    {
        // Null = the request continues to the MCP controller.
        return null;
    }

    /**
     * The same 401 the PAT path emits, from the same builder — a client must not
     * have to tell which credential type it guessed wrong about.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->challenge->unauthorized(
            $request,
            'invalid_token',
            'The OAuth access token is invalid, expired or revoked.',
        );
    }

    /**
     * `Authorization: Bearer <token>` where `<token>` is NOT a personal access
     * token. Anything shaped like a PAT belongs to {@see McpTokenAuthenticator}
     * and must not reach league's JWT parser, which would only fail on it.
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

        return $this->tokenGenerator->looksLikeToken($presented) ? null : $presented;
    }

    /**
     * Narrows a `mixed` claim / attribute to the `list<string>` the seam
     * promises. A malformed value degrades to "no scopes" — the same
     * fail-closed rule {@see McpScopeChecker} applies on the reading side.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        /** @var list<string> $values */
        $values = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $values[] = $item;
            }
        }

        return $values;
    }
}
