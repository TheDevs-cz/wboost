<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use WBoost\Web\Entity\User;
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
            'api_token' => [
                'pattern' => '^/api/(token|authorize)$',
                'security' => false,
            ],
            'api' => [
                'pattern' => '^/api',
                'stateless' => true,
                'provider' => 'api_user_provider',
                'oauth2' => true,
            ],
            // MCP server (personal access tokens). MUST stay above `main`:
            // firewalls match in order, and under `main` an unauthenticated
            // POST /_mcp would be answered by its form_login entry point with a
            // 302 to /login instead of the 401 challenge an MCP client needs.
            //
            // The UserChecker is deliberately the same one `main` uses: it
            // blocks users with confirmed=false, so a never-activated (or
            // deactivated) account cannot keep using a token it still holds. A
            // deleted user needs no check — the token row cascades away with it.
            'mcp' => [
                'pattern' => '^/_mcp',
                'stateless' => true,
                'provider' => 'api_user_provider',
                'user_checker' => UserChecker::class,
                'custom_authenticators' => [
                    McpTokenAuthenticator::class,
                ],
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
                    'default_target_path' => '/',
                    'enable_csrf' => true,
                ],
                'logout' => [
                    'path' => 'logout',
                    'target' => '/',
                ],
            ],
        ],
        'access_control' => [
            [
                'path' => '^/api/(token|authorize|docs|contexts/.*|\.well-known/.*)',
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
            [
                'path' => '^/_mcp',
                'roles' => [AuthenticatedVoter::IS_AUTHENTICATED_FULLY],
            ],
            [
                'path' => '^/(login|registration|forgotten-password|set-password/.*|.*/preview|nahled-manualu/.*|stahnout-logo/.*|email-signature-variant/.*/vcard-qr-code\.png|email-signature-demo/vcard-qr-code\.png|weekly-menu/.*/public|weekly-menu/.*/approval/.*)',
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
