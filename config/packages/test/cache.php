<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'cache' => [
            'pools' => [
                // MCP protocol sessions on disk instead of Redis while testing.
                //
                // The suite has no Redis: CI runs one `postgres` service and
                // nothing else. Every other pool tolerates that silently — a
                // Symfony cache adapter that cannot reach its backend logs the
                // failure and answers "miss", so the ~670 tests that never
                // assert on caching pass either way. MCP sessions are the one
                // thing that CANNOT: `initialize` stores a session and the very
                // next request has to read it back, so a store that quietly
                // forgets everything makes the transport answer 202 with no
                // `Mcp-Session-Id` and every handshake in tests/Mcp/ fails. That
                // is precisely what went red in CI on 2026-08-06 — 13 tests,
                // all of them green locally, because docker compose does run
                // Redis.
                //
                // Filesystem rather than `cache.adapter.array`: KernelBrowser
                // reboots the kernel between requests, so an in-memory pool is
                // wiped between `initialize` and the call that follows it — the
                // session has to outlive the kernel that created it.
                //
                // This does not weaken what production does. The reason the
                // real pool is Redis (config/packages/cache.php) is blue/green
                // deploys handing the next request to a different container and
                // FrankenPHP worker mode running several processes per
                // container — neither exists in a single-process test run, where
                // one cache dir is shared by definition. Both are ordinary PSR-6
                // pools storing the same serialized session, so nothing about
                // the transport behaves differently.
                'cache.mcp_session' => [
                    'adapter' => 'cache.adapter.filesystem',
                    'default_lifetime' => 3600,
                ],
                // NOTE: `cache.oauth2_client_registration` is deliberately NOT
                // overridden here. The rate limiter that would use it is
                // pointed at a plain in-memory storage service instead — see
                // config/packages/test/rate_limiter.php, which explains why no
                // cache pool (array included) can hold limiter state under
                // test.
            ],
        ],
    ],
]);
