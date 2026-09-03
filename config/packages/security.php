<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use WBoost\Web\Entity\User;
use WBoost\Web\Mcp\Security\McpOAuthAuthenticator;
use WBoost\Web\Mcp\Security\McpTokenAuthenticator;
use WBoost\Web\Services\Security\FacebookAuthenticator;
use WBoost\Web\Services\Security\UserChecker;

return App::config([
    'security' => [
        'providers' => [
            'user_provider' => [
                'entity' => [
                    'class' => User::class,
                    'property' => 'email',
                ],
            ],
            'api_user_provider' => [
                'entity' => [
                    'class' => User::class,
                    'property' => 'id',
                ],
            ],
        ],
        'password_hashers' => [
            PasswordAuthenticatedUserInterface::class => [
                'algorithm' => 'auto',
            ],
        ],
        'firewalls' => [
            'dev' => [
                'pattern' => '^/(_profiler|_wdt|css|images|js|theme|assets)/',
                'security' => false,
            ],
            'stateless' => [
                'pattern' => '^(/-/health-check|/media/cache|/sitemap)',
                'stateless' => true,
                'security' => false,
            ],
            // The OAuth2 TOKEN endpoint authenticates the CLIENT (secret or
            // PKCE verifier), never a Symfony user — so it must not sit behind
            // any firewall. `/api/authorize` used to share this entry and was
            // moved out by S8-T1; see the `api` pattern right below.
            'api_token' => [
                'pattern' => '^/api/token$',
                'security' => false,
            ],
            // Everything under /api EXCEPT the authorization endpoint.
            //
            // `/api/authorize` is the one OAuth endpoint that has to identify
            // the END USER (the resource owner whose id becomes the token's
            // `sub`), and the bundle reads that user straight off
            // `Security::getUser()` — it throws outright if there is none. A
            // stateless, bearer-only firewall can never supply it, so the
            // request has to reach the session-backed `main` firewall instead.
            //
            // Firewalls match in ORDER and the first hit wins, so the only way
            // to let `/api/authorize` fall through to `main` (which has no
            // pattern and therefore catches everything left) is to carve it out
            // of this pattern. The negative lookahead does exactly that and
            // touches nothing else: `/api/token` was already claimed above,
            // and every other `/api/...` path — including anything that merely
            // STARTS with `/api/authorize`, since the lookahead is anchored
            // with `$` — still matches here and keeps its bearer-token
            // behaviour unchanged.
            'api' => [
                'pattern' => '^/api(?!/authorize$)',
                'stateless' => true,
                'provider' => 'api_user_provider',
                'oauth2' => true,
            ],
            // MCP server. MUST stay above `main`: firewalls match in order, and
            // under `main` an unauthenticated POST /_mcp would be answered by
            // its form_login entry point with a 302 to /login instead of the
            // 401 challenge an MCP client needs.
            //
            // TWO credentials, one behaviour (S8-T6). A personal access token
            // (`wb_mcp_…`) and an OAuth 2.1 bearer issued by this app's own
            // authorization server resolve to the SAME User and stash their
            // scopes under the SAME token attribute, so voters, McpScopeChecker,
            // McpToolGate and `tools/list` filtering cannot tell them apart.
            //
            // The two authenticators' `supports()` are DISJOINT (PAT = the
            // `wb_mcp_` prefix, OAuth = every other bearer) and that is
            // load-bearing, not tidiness: Symfony runs every supporting
            // authenticator in turn and does NOT stop at the first success, so
            // two authenticators claiming one request would let the second
            // one's failure overwrite the first one's success with a 401. It is
            // also why the bundle's own `oauth2: true` cannot be used here —
            // its `supports()` claims every bearer, PATs included. See
            // McpOAuthAuthenticator's docblock.
            //
            // The UserChecker is deliberately the same one `main` uses: it
            // blocks users with confirmed=false, so a never-activated (or
            // deactivated) account cannot keep using a credential it still
            // holds — on BOTH paths, since it belongs to the firewall rather
            // than to either authenticator. A deleted user needs no check: the
            // PAT row cascades away with it, and an OAuth token's `sub` stops
            // resolving.
            'mcp' => [
                'pattern' => '^/_mcp',
                'stateless' => true,
                'provider' => 'api_user_provider',
                'user_checker' => UserChecker::class,
                'custom_authenticators' => [
                    McpTokenAuthenticator::class,
                    McpOAuthAuthenticator::class,
                ],
                // Only ONE entry point per firewall, and both authenticators
                // fail into the same McpChallenge anyway. This one answers the
                // "nothing usable was presented" case.
                'entry_point' => McpTokenAuthenticator::class,
            ],
            'main' => [
                'lazy' => true,
                'provider' => 'user_provider',
                'user_checker' => UserChecker::class,
                'custom_authenticators' => [
                    FacebookAuthenticator::class,
                ],
                // Required once the firewall has more than one authenticator:
                // unauthenticated users still get the login form.
                'entry_point' => 'form_login',
                'form_login' => [
                    'login_path' => 'login',
                    'check_path' => 'login',
                    // NOT '/': the apex is the static marketing site (D61 in
                    // the infra repo), so a successful login must land on the
                    // app's own entry point instead of the landing page.
                    'default_target_path' => '/dashboard',
                    'enable_csrf' => true,
                ],
                'logout' => [
                    'path' => 'logout',
                    'target' => '/',
                ],
            ],
        ],
        'access_control' => [
            // OAuth 2.1 authorization endpoint (S8-T1). Handled by the `main`
            // firewall (see the `api` firewall pattern above), and DELIBERATELY
            // not public: demanding a full login is what turns an anonymous
            // visit into a 302 to the Czech login form instead of the bundle's
            // "A logged in user is required to resolve the authorization
            // request" 500. The `main` ExceptionListener stores the full
            // authorize URL (query string included) under
            // `_security.main.target_path`, so the user lands back here after
            // logging in and the flow continues — which only works because the
            // firewall handling the request is literally named `main`, the same
            // name the login form's success handler reads the target path for.
            [
                'path' => '^/api/authorize$',
                'roles' => [AuthenticatedVoter::IS_AUTHENTICATED_FULLY],
            ],
            // `register` is the RFC 7591 client-registration endpoint (S8-T4).
            // Unauthenticated BY DESIGN — a connector introduces itself before
            // any user is in the loop — and unlike `/api/token` it needs no
            // firewall carve-out: the `api` firewall's oauth2 authenticator
            // simply does not claim a request with no bearer, so this rule is
            // the only thing standing between it and the `^/api` login
            // requirement below. Whether it answers at all is a separate
            // switch (`OAUTH2_DYNAMIC_CLIENT_REGISTRATION`, off by default);
            // reaching a 404 without a session is the intended behaviour.
            [
                'path' => '^/api/(token|register|docs|contexts/.*|\.well-known/.*)',
                'roles' => [AuthenticatedVoter::PUBLIC_ACCESS],
            ],
            [
                'path' => '^/api',
                'roles' => [AuthenticatedVoter::IS_AUTHENTICATED_FULLY],
            ],
            // RFC 9728 resource metadata (S1-T4): an MCP client reads it while
            // UNAUTHENTICATED — it is the document the 401 challenge points at.
            // Must stay above the `^/` catch-all, which would otherwise demand
            // a login for it.
            [
                'path' => '^/\.well-known/oauth-protected-resource',
                'roles' => [AuthenticatedVoter::PUBLIC_ACCESS],
            ],
            // RFC 8414 authorization-server metadata (S8-T2): the document the
            // RFC 9728 resource metadata above points a client at. Read while
            // UNAUTHENTICATED by definition — it is how a client discovers
            // where to send the user to log in — so it must stay above the
            // `^/` catch-all, which would answer it with a redirect to /login.
            [
                'path' => '^/\.well-known/oauth-authorization-server',
                'roles' => [AuthenticatedVoter::PUBLIC_ACCESS],
            ],
            [
                'path' => '^/_mcp',
                'roles' => [AuthenticatedVoter::IS_AUTHENTICATED_FULLY],
            ],
            // The apex and the static pages belong to nginx (D61), not to
            // Symfony — but if the landing container is down or a router rule
            // is wrong they fall through to here, and a clean 404 is a far
            // easier thing to debug than a silent redirect to /login. Anchor
            // every one with `$`: a bare `^/` would open the entire app.
            // Extend the second rule whenever a static page is added.
            [
                'path' => '^/$',
                'roles' => [AuthenticatedVoter::PUBLIC_ACCESS],
            ],
            [
                'path' => '^/(ochrana-osobnich-udaju|obchodni-podminky)/?$',
                'roles' => [AuthenticatedVoter::PUBLIC_ACCESS],
            ],
            [
                'path' => '^/(robots\\.txt|sitemap\\.xml)$',
                'roles' => [AuthenticatedVoter::PUBLIC_ACCESS],
            ],
            [
                'path' => '^/(login|registration|forgotten-password|set-password/.*|.*/preview|nahled-manualu/.*|stahnout-logo/.*|stahnout-mockup/.*|email-signature-variant/.*/vcard-qr-code\.png|email-signature-demo/vcard-qr-code\.png|weekly-menu/.*/public|weekly-menu/.*/approval/.*)',
                'roles' => [AuthenticatedVoter::PUBLIC_ACCESS],
            ],
            // Facebook OAuth: login start/callback for anonymous users + Meta's
            // server-to-server data-deletion callback. The connect start/check
            // controllers under the same prefix enforce IS_AUTHENTICATED_FULLY
            // themselves via #[IsGranted].
            [
                'path' => '^/oauth/facebook/',
                'roles' => [AuthenticatedVoter::PUBLIC_ACCESS],
            ],
            [
                'path' => '^/',
                'roles' => [AuthenticatedVoter::IS_AUTHENTICATED_FULLY],
            ],
        ],
        'role_hierarchy' => [
            User::ROLE_DESIGNER => ['ROLE_USER'],
            User::ROLE_ADMIN => [User::ROLE_DESIGNER],
        ],
    ],
]);
