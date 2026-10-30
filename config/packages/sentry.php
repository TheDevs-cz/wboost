<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

return App::config([
    'sentry' => [
        'dsn' => '%env(SENTRY_DSN)%',
        'tracing' => [
            'enabled' => true,
        ],
        'register_error_listener' => false,
        'register_error_handler' => false,
        'logger' => 'monolog.logger',
        'messenger' => [
            'enabled' => true,
            'capture_soft_fails' => true,
        ],
        'options' => [
            'environment' => '%kernel.environment%',
            'send_default_pii' => true,
            'ignore_exceptions' => [
                AccessDeniedException::class,
                NotFoundHttpException::class,
                // Client-side by definition and scanner noise in practice: a
                // 429 is a rate limiter doing its job (WEB-4S, a bot on
                // POST /poptavka), a 405 a probe GETting a POST-only route
                // (WEB-2A). Other 4xx still report — by choice, 2026-08-31.
                MethodNotAllowedHttpException::class,
                TooManyRequestsHttpException::class,
            ],
            'traces_sample_rate' => '%env(float:SENTRY_TRACES_SAMPLE_RATE)%',
            'profiles_sample_rate' => '%env(float:SENTRY_PROFILES_SAMPLE_RATE)%',
        ],
    ],
]);
