<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use WBoost\Web\Entity\User;
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
            'main' => [
                'lazy' => true,
                'provider' => 'user_provider',
                'user_checker' => UserChecker::class,
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
            [
                'path' => '^/(login|registration|forgotten-password|set-password/.*|.*/preview|nahled-manualu/.*|stahnout-logo/.*|email-signature-variant/.*/vcard-qr-code\.png|email-signature-demo/vcard-qr-code\.png|weekly-menu/.*/public|weekly-menu/.*/approval/.*)',
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
