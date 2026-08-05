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
                // Rendered Gotenberg preview bytes (see
                // Services/Editor/TemplateVariantImageRenderer). Its OWN pool
                // rather than cache.app for three reasons:
                //  - own namespace, so a `cache:pool:clear` of previews cannot
                //    touch application cache and vice versa;
                //  - own (short) default_lifetime — these are disposable;
                //  - its own tag set, so invalidating a variant's previews does
                //    not walk application tags.
                //
                // NOTE it deliberately shares the Redis *server*: `maxmemory`
                // and `allkeys-lru` are server-wide in Redis, so putting this on
                // a separate logical DB would give namespace isolation but NOT
                // memory isolation — it would not stop preview blobs evicting
                // application keys. What actually prevents that is capacity plus
                // bounded entries: the renderer only caches renders that are
                // provably independent of the user's input (so one entry per
                // variant/slice/canvas-version, not one per keystroke) and
                // refuses to store anything oversized. Redis on the box was
                // sized up to match (infra repo, apps/wboost/compose.yaml).
                'cache.gotenberg_preview' => [
                    'adapter' => 'cache.adapter.redis_tag_aware',
                    'default_lifetime' => 21600, // 6h — a working session, then let it go
                ],
            ],
        ],
    ],
]);
