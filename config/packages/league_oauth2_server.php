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
 *
 * `refresh_token` stays OFF deliberately: turning it on is a token-lifetime
 * decision that belongs with S8-T6 (swap the authenticator), not with enabling
 * the grant. Until then an auth-code access token simply expires after
 * `access_token_ttl` and the client repeats the (already-consented) flow.
 *
 * @var list<string>
 */
$supportedGrants = [
    OAuth2Grants::CLIENT_CREDENTIALS,
    OAuth2Grants::AUTHORIZATION_CODE,
];

return App::config([
    'parameters' => [
        'oauth2.grant_types_supported' => $supportedGrants,
    ],

    'league_oauth2_server' => [
        'authorization_server' => [
            'private_key' => '%env(base64:OAUTH2_PRIVATE_KEY)%',
            'private_key_passphrase' => '%env(OAUTH2_PRIVATE_KEY_PASSPHRASE)%',
            'encryption_key' => '%env(OAUTH2_ENCRYPTION_KEY)%',
            'encryption_key_type' => 'plain',
            'access_token_ttl' => 'PT1H',

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
        'role_prefix' => 'ROLE_OAUTH2_',
    ],
]);
