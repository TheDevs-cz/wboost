<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Cache\Psr16Cache;
use WBoost\Web\Mcp\Transport\BufferedMcpController;
use WBoost\Web\Mcp\Transport\BufferStreamedResponse;

return App::config([
    'services' => [
        // The bundle's `cache` session store is a Mcp\Server\Session\Psr16SessionStore,
        // whose first argument is type-hinted Psr\SimpleCache\CacheInterface — a
        // PSR-16 cache, NOT a Symfony (PSR-6) pool. Pointing
        // mcp.http.session.cache_pool straight at `cache.mcp_session` would blow
        // up at compile time, so the pool is wrapped here and the bundle is
        // handed the wrapper. (The bundle only auto-creates such a wrapper for
        // its own default id `cache.mcp.sessions`, over cache.app.)
        'mcp.session.psr16' => [
            'class' => Psr16Cache::class,
            'arguments' => [service('cache.mcp_session')],
            'autowire' => false,
            'autoconfigure' => false,
        ],

        // FrankenPHP guard: the bundle's controller returns a *flushing*
        // StreamedResponse whenever the transport answers text/event-stream,
        // and under resident PHP that commits output early and kills the NEXT
        // request on the same worker ("headers already sent"). The bundle
        // exposes no switch for it, so the controller is decorated and the body
        // buffered. See the class docblocks — that is where the reasoning lives.
        //
        // Registered here rather than in config/services.php because the
        // src/Mcp/ autowiring there is scoped to Tool/ + Design/ + Security/,
        // and these two are neither: they are transport plumbing that must be
        // wired by hand (explicit `.inner`, no autoconfiguration).
        BufferStreamedResponse::class => [
            'autowire' => false,
            'autoconfigure' => false,
        ],

        BufferedMcpController::class => [
            'decorates' => 'mcp.server.controller',
            'arguments' => [service('.inner'), service(BufferStreamedResponse::class)],
            'autowire' => false,
            'autoconfigure' => false,
        ],
    ],

    'mcp' => [
        'app' => 'wboost',
        'version' => '0.1.0',
        'description' => 'Design and manage wboost brand templates from an AI client.',
        'website_url' => 'https://wboost.cz',

        // Sent to every client on connect, so keep it short and load-bearing.
        // Sent to every client on connect, so keep it short — and keep it TRUE
        // of the tools actually registered. It shipped once describing a design
        // DSL that did not exist yet; a client cannot discover that the
        // instructions are aspirational, it just tries and fails. Whatever this
        // says must be checkable against src/Mcp/Tool/.
        'instructions' => <<<'INSTRUCTIONS'
            wboost is a brand-manual platform: a project holds brand manuals (colours,
            fonts, logos) and templates — designed canvases whose text and image slots are
            filled in and rendered to images.
            Call get_context first. It tells you which projects this account can reach and
            gives you their ids, exact font face strings and brand colours; nothing else
            makes sense without it. From there: find_templates lists a project's templates
            and variants, describe_variant tells you exactly what one variant can be filled
            with, render_variant returns a fast downscaled preview, and export_variant
            returns the final full-size PNG.
            Two things worth knowing. Everything is addressed by UUID — the inputs map is
            keyed by the inputId that describe_variant reports, never by an input's name.
            And preview with render_variant while you iterate: export_variant is the
            deliverable, it is stricter, and it is counted.
            Designing new templates is not available through this connector yet; that is
            done in the wboost editor.
            INSTRUCTIONS,

        'client_transports' => [
            // Remote HTTP server only — there is no local stdio process to run.
            'stdio' => false,
            'http' => true,
        ],

        'http' => [
            'path' => '/_mcp',

            // DNS-rebinding protection. Unset it defaults to localhost-only, which
            // means every production request answers 403 — so production belongs in
            // this list explicitly. `localhost` covers local docker compose and the
            // Symfony test client (its default Host header).
            'allowed_hosts' => ['wboost.cz', 'localhost'],

            'session' => [
                'store' => 'cache',
                'cache_pool' => 'mcp.session.psr16',
                'prefix' => 'mcp-session-',
                'ttl' => 3600,
            ],
        ],
    ],
]);
