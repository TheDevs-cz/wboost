<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'cache' => [
            'default_redis_provider' => '%env(REDIS_CACHE_DSN)%',
            'app' => 'cache.adapter.redis_tag_aware',
            'pools' => [
                'cache.flysystem.psr6' => [
                    'adapters' => ['cache.app'],
                ],
            ],
        ],
    ],
]);
