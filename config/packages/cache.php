<?php

declare(strict_types=1);

use Symfony\Config\FrameworkConfig;

return static function (FrameworkConfig $framework): void {
    $cacheConfig = $framework->cache();

    $cacheConfig->defaultRedisProvider('%env(REDIS_CACHE_DSN)%');

    $cacheConfig->app('cache.adapter.redis_tag_aware');

    $cacheConfig->pool('cache.flysystem.psr6')
        ->adapters(['cache.app']);
};
