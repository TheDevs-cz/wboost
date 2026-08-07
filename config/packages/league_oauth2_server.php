<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use WBoost\Web\Mcp\Security\McpScope;

/**
 * The grants this authorization server offers — the SINGLE source of truth for
 * both the five `enable_*_grant` switches below and the `grant_types_supported`
 * field of the RFC 8414 metadata document
 * ({@see \WBoost\Web\Controller\OAuth2\AuthorizationServerMetadataController}).
 *
 * The bundle exposes no container parameter naming the enabled grants, so the
 * only way for the metadata to stop lying when a grant is switched on or off is
 * to make one list drive both. Adding a grant here is therefore a one-line
 * change that is automatically advertised; flipping a boolean in isolation is
 * not possible.
 *
 * - `client_credentials` (S1, in production): the REST API at `/api`, consumed
 *   service-to-service by the mfkfm backoffice. Untouched by Stage 8.
 * - `authorization_code` (S8-T1): the interactive flow — the ONLY mechanism
 *   claude.ai / ChatGPT connectors can use, since they cannot send custom
 *   headers and therefore cannot carry a personal access token.
 * - `refresh_token` (S8-T6): see below — it was already being ISSUED before it
 *   was enabled, which is a bug, not a feature.
 *
 * ## Why `refresh_token` had to be decided here (S8-T6)
 *
 * With the flag off, league STILL put a `refresh_token` in every
 * authorization-code response: `enable_refresh_token_grant` only controls
 * whether `grant_type=refresh_token` can be REDEEMED at the token endpoint, and
 * the bundle hands `AuthCodeGrant` a refresh-token repository plus a TTL
 * unconditionally. Clients were therefore handed a credential the server would
 * answer `unsupported_grant_type` to. The two ways out were to stop issuing one
 * (a custom grant subclass or a null repository — fighting the library) or to
 * accept it, and accepting it is also what the product needs: `access_token_ttl`
 * is one hour, so without redemption every connector re-runs the whole browser
 * flow every hour.
 *
 * **The trade-off, stated plainly:** MCP connectors are PUBLIC clients with no
 * secret, so a stolen refresh token is usable by whoever holds it. Three things
 * bound that, none of which apply to the access token itself:
 *
 * - **Rotation.** `revoke_refresh_tokens` defaults to true, so redeeming a
 *   refresh token revokes it and mints a new one. A stolen token is single-use,
 *   and a replay after the legitimate client has refreshed simply fails.
 * - **Revocability.** Refresh tokens are rows, not self-contained JWTs — the
 *   credentials revoker (and `app:oauth-client:revoke`) can kill them, which is
 *   exactly what cannot be done to an issued access token.
 * - **A bounded idle window** (`refresh_token_ttl` below).
 *
 * `grant_types_supported` in the RFC 8414 metadata follows automatically,
 * because it is this same list.
 *
 * @var list<string>
 */
$supportedGrants = [
    OAuth2Grants::CLIENT_CREDENTIALS,
    OAuth2Grants::AUTHORIZATION_CODE,
    OAuth2Grants::REFRESH_TOKEN,
];

return App::config([
    'parameters' => [
        'oauth2.grant_types_supported' => $supportedGrants,

        /*
         * RFC 7591 dynamic client registration (S8-T4) — **the one switch**.
         *
         * It gates BOTH `POST /api/register`
         * ({@see \WBoost\Web\Controller\OAuth2\ClientRegistrationController})
         * and the `registration_endpoint` field of the RFC 8414 metadata
         * document, so the server can never advertise an endpoint that 404s or
         * hide one that answers.
         *
         * ## It is OFF — and what made it unsafe is now fixed (S8-T5)
         *
         * Registration is unauthenticated by design — a connector introduces
         * itself before any user is involved. Combined with an AUTO-APPROVING
         * authorization endpoint (what
         * `ApproveAuthorizationRequestListener` used to be) that meant: anyone
         * registers a client pointing at a host they own, sends a logged-in
         * wboost user a link to `/api/authorize`, and receives a token scoped
         * to that user's projects — one click, no prompt. PKCE does not help
         * (the attacker IS the client and holds the verifier), https-only
         * redirect URIs do not help (attackers own https hosts), rate limiting
         * does not help. The consent screen does.
         *
         * {@see \WBoost\Web\Services\OAuth2\ResolveAuthorizationRequestListener}
         * now shows one: a first-time authorization is parked, the user is told
         * which application is asking (name AND redirect host), which wboost
         * account is about to be connected and exactly what the app will be
         * able to do, and only a click resolves it. Approvals are remembered
         * per user and client WITH their scopes, so a client that later asks
         * for more is prompted again instead of silently upgraded.
         *
         * The registrar's constraints (public clients only, https-or-loopback
         * redirect URIs, no wildcards, MCP scopes only, no outbound fetch)
         * bound what a registration can BE; consent is what decides whether the
         * user agreed to it. Both halves now exist, so flipping this to `1` is
         * a deployment decision rather than a code change — it stays `0` in the
         * committed default until that decision is made.
         */
        'oauth2.dynamic_client_registration_enabled' => '%env(bool:OAUTH2_DYNAMIC_CLIENT_REGISTRATION)%',
    ],

    'league_oauth2_server' => [
        'authorization_server' => [
            'private_key' => '%env(base64:OAUTH2_PRIVATE_KEY)%',
            'private_key_passphrase' => '%env(OAUTH2_PRIVATE_KEY_PASSPHRASE)%',
            'encryption_key' => '%env(OAUTH2_ENCRYPTION_KEY)%',
            'encryption_key_type' => 'plain',
            'access_token_ttl' => 'PT1H',

            // The IDLE window, not the session length: league mints a fresh
            // refresh token (with a fresh TTL) on every redemption, so a
            // connector in daily use never expires, while one nobody has
            // touched for a month has to be re-authorized in the browser.
            // Spelled out rather than left to the bundle's identical default,
            // because for a public client this number IS the security
            // boundary — see the trade-off note above.
            'refresh_token_ttl' => 'P1M',

            'enable_client_credentials_grant' => in_array(OAuth2Grants::CLIENT_CREDENTIALS, $supportedGrants, true),
            'enable_password_grant' => in_array(OAuth2Grants::PASSWORD, $supportedGrants, true),
            'enable_refresh_token_grant' => in_array(OAuth2Grants::REFRESH_TOKEN, $supportedGrants, true),
            'enable_auth_code_grant' => in_array(OAuth2Grants::AUTHORIZATION_CODE, $supportedGrants, true),
            'enable_implicit_grant' => in_array(OAuth2Grants::IMPLICIT, $supportedGrants, true),

            // PKCE is MANDATORY for public clients (OAuth 2.1 / RFC 7636). The
            // bundle already defaults this to true; it is spelled out because
            // the whole point of the auth-code grant here is native/desktop MCP
            // clients that cannot keep a secret — for them the code challenge
            // is the only thing standing between an intercepted redirect and a
            // stolen token. `plain` challenges additionally require a per-client
            // opt-in the bundle refuses by default, so S256 is what is offered.
            'require_code_challenge_for_public_clients' => true,

            'persist_access_token' => true,
        ],
        'resource_server' => [
            'public_key' => '%env(base64:OAUTH2_PUBLIC_KEY)%',
        ],
        'scopes' => [
            // `api` is the legacy blanket scope of the client_credentials REST
            // API — it predates scoping and every existing token carries it.
            //
            // The MCP scopes are appended from {@see McpScope} rather than
            // retyped, because the bundle REJECTS (`invalid_scope`) any scope a
            // client asks for that is not registered here: a hand-listed copy
            // would silently break every authorization the day a case is added.
            // `tests/OAuth2/AuthorizationServerScopesTest.php` asserts the
            // round-trip, which also catches the one case derivation cannot
            // (a container cached before the enum changed — the enum file is
            // not a config resource, so `cache:clear` is the fix).
            'available' => array_merge(['api'], McpScope::values()),

            // Unchanged: a request that names no scope still gets `api`, so the
            // in-production client_credentials flow behaves exactly as before.
            'default' => ['api'],
        ],
        'persistence' => [
            'doctrine' => [
                'entity_manager' => 'default',
            ],
        ],

        'client' => [
            /*
             * Client secrets are stored HASHED, never in clear text.
             *
             * The bundle's own default is `true` — it registers a migrating
             * hasher that also accepts a clear-text column value, so an
             * installation predating bundle 1.2 keeps authenticating while its
             * rows are still unhashed. That is a migration aid, and it is
             * deprecated: leaving it on means the database keeps holding
             * credentials that are directly replayable by anyone who can read
             * the table (a dump, a backup, an Adminer session).
             *
             * Setting it to `false` is only safe once no clear-text row is
             * left, because verification of one would simply start failing.
             * That migration has been run on production (2026-08-07):
             * `bin/console league:oauth2-server:rehash-client-secrets` hashes
             * the EXISTING secret in place — the value a consumer sends is
             * unchanged, so it is transparent to them, and both rows came back
             * as `$2y$` bcrypt. The live `client_credentials` consumer was
             * verified against `/api/token` before and after (200 both times).
             *
             * ORDER MATTERS if this ever runs against another environment:
             * rehash FIRST, flip this SECOND. Flipping first breaks every
             * client whose secret is still clear text.
             */
            'allow_plaintext_secrets' => false,
        ],

        'role_prefix' => 'ROLE_OAUTH2_',
    ],
]);
