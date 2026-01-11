<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use WBoost\Web\Entity\User;

return App::config([
    'security' => [
        'providers' => [
            'user_provider' => [
                'entity' => [
                    'class' => User::class,
                    'property' => 'email',
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
            'main' => [
                'lazy' => true,
                'provider' => 'user_provider',
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
                'path' => '^/(login|registration|forgotten-password|reset-password|.*/preview|nahled-manualu/.*|stahnout-logo/.*|email-signature-variant/.*/vcard-qr-code\.png)',
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
