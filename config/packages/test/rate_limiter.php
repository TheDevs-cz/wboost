<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Rate-limiter state lives in a plain object while testing, not in a cache pool
 * — and both halves of that are load-bearing.
 *
 * **Why not the real (Redis) pool:** CI runs one service, `postgres`. A Symfony
 * cache adapter that cannot reach its backend logs the failure and answers
 * "miss", so a limiter over Redis would silently never limit and the test that
 * asserts it does would be asserting nothing (risk R11 in docs/plans/mcp-server.md).
 *
 * **Why not `cache.adapter.array` either — the trap that cost an hour:** every
 * framework cache pool is tagged `kernel.reset`, and `ArrayAdapter::reset()`
 * CLEARS it. The test kernel's `services_resetter` therefore wipes the pool
 * between requests, so an array-backed limiter counts to exactly one no matter
 * how many requests a test makes, and the limit never trips.
 *
 * `InMemoryStorage` is registered here as an ordinary service with no
 * `kernel.reset` tag, which gives exactly the lifetime the suite wants:
 *
 * - it is recreated on every kernel boot, so the default reboot-per-request
 *   behaviour means each test (and each request of it) starts with a full
 *   budget — no test can be made to fail by how many ran before it;
 * - it survives `$client->disableReboot()`, which is how the ONE test that has
 *   to watch the limit trip keeps a single container alive across its requests.
 *
 * Production is untouched: there the limiter uses the Redis-backed
 * `cache.oauth2_client_registration` pool (config/packages/cache.php), which is
 * what a multi-container blue/green deployment needs.
 */
return App::config([
    'services' => [
        'limiter.storage.test_in_memory' => [
            'class' => InMemoryStorage::class,
            'autowire' => false,
            'autoconfigure' => false,
        ],
    ],

    'framework' => [
        'rate_limiter' => [
            // Merges over config/packages/rate_limiter.php — policy, limit and
            // interval stay defined ONCE, there, so a test cannot assert a
            // limit production does not have. `storage_service` takes
            // precedence over `cache_pool`.
            'oauth2_client_registration' => [
                'storage_service' => 'limiter.storage.test_in_memory',
            ],
        ],
    ],
]);
