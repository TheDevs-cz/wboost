<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/**
 * Rate limiters. Consumed via `#[RateLimit('<name>')]` on a controller, which
 * Symfony's RateLimitAttributeListener turns into a 429 with `Retry-After` —
 * so a limiter is added here and named at the controller, with no glue code.
 */
return App::config([
    'framework' => [
        'rate_limiter' => [
            /*
             * RFC 7591 dynamic client registration (S8-T4).
             *
             * This is NOT the thing that makes unauthenticated registration
             * safe — see config/packages/league_oauth2_server.php for what
             * does, and why the endpoint is off until it exists. An attacker
             * with a handful of source addresses walks straight through a
             * per-IP limit. What it does close is the one abuse that survives
             * a consent screen: an endpoint that INSERTs a row and needs no
             * credential is otherwise a way to fill `oauth2_client` from the
             * open internet.
             *
             * Ten per hour per address is generous for the real use — a
             * connector registers ONCE per user, and the client id is then
             * stored by the client — and cheap to be wrong about, since a
             * legitimate client that trips it retries after the `Retry-After`
             * the listener sends.
             *
             * `sliding_window` rather than `fixed_window` so the limit cannot
             * be doubled by straddling a window boundary. No `lock_factory`:
             * symfony/lock is not installed, the config default `auto`
             * resolves to "no lock" in that case, and the worst a race can do
             * here is let one extra registration through.
             */
            'oauth2_client_registration' => [
                'policy' => 'sliding_window',
                'limit' => 10,
                'interval' => '1 hour',

                // Its OWN pool, not the `cache.rate_limiter` default (a child
                // of cache.app). cache.app is Redis, and the test suite has no
                // Redis — a Symfony adapter that cannot reach its backend logs
                // the failure and answers "miss", so the limiter would silently
                // never limit and a test asserting it does would be asserting
                // nothing. The pool below is overridden per environment
                // instead; see config/packages/test/cache.php.
                'cache_pool' => 'cache.oauth2_client_registration',
            ],
        ],
    ],
]);
