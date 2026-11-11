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

            /*
             * The public lead endpoint behind the static landing pages
             * (LandingLeadController, POST /poptavka). It INSERTs and sends an
             * e-mail with no credential, so it must not become a way to flood
             * either. Twenty per hour per address leaves room for a visitor
             * who sends the form and then a few chat messages, or an office
             * where several people share one public IP.
             */
            'landing_lead' => [
                'policy' => 'sliding_window',
                'limit' => 20,
                'interval' => '1 hour',

                'cache_pool' => 'cache.landing_lead',
            ],

            /*
             * The public client link of a calendar (/k/{token}/…). Whoever
             * holds the link works with no account, so every endpoint behind
             * it has a budget; the controllers consume them by hand (their
             * 429 is the calendar endpoints' `{ error }` JSON, not the
             * kernel's error page). All six share one cache pool — the
             * limiter id is part of every key.
             *
             * calendar_client_upload — POST /k/{token}/asset, key "link token
             * + address". Files of up to 10 MB each: the endpoint must not
             * become free storage. 200 per hour: a municipal clerk fills ~55
             * week photos in one bulk upload, may repeat the whole batch and
             * still replace a few dozen single photos within the hour.
             */
            'calendar_client_upload' => [
                'policy' => 'sliding_window',
                'limit' => 200,
                'interval' => '1 hour',

                'cache_pool' => 'cache.calendar_client',
            ],

            /*
             * The same endpoint, key "link token" alone: changing addresses
             * must not multiply the budget above. 500 per hour lets two
             * offices fill a year each within the same hour; the total a
             * calendar may hold is capped separately
             * (CalendarAssetUploader::MAX_CLIENT_BYTES).
             */
            'calendar_client_upload_link' => [
                'policy' => 'sliding_window',
                'limit' => 500,
                'interval' => '1 hour',

                'cache_pool' => 'cache.calendar_client',
            ],

            /*
             * POST /k/{token}/save, per link. Every save takes a row lock,
             * validates up to 2 MB and writes history. Saving is explicit (a
             * button), so 600 per hour — one every six seconds, all hour — is
             * far beyond a person and still stops a loop.
             */
            'calendar_client_save' => [
                'policy' => 'sliding_window',
                'limit' => 600,
                'interval' => '1 hour',

                'cache_pool' => 'cache.calendar_client',
            ],

            /*
             * GET /k/{token}/history, per link: 200 rows per answer, opened
             * by hand from a dialog.
             */
            'calendar_client_history' => [
                'policy' => 'sliding_window',
                'limit' => 600,
                'interval' => '1 hour',

                'cache_pool' => 'cache.calendar_client',
            ],

            /*
             * POST /k/{token}/export, per link — counted only when the request
             * starts a NEW render (an unchanged calendar is answered with the
             * file that is already there, for free). A full year is minutes
             * of headless Chromium on the single consumer: 10 per hour lets a
             * client export both variants after each of five rounds of
             * changes and stops a loop from queueing work for hours.
             */
            'calendar_client_export' => [
                'policy' => 'sliding_window',
                'limit' => 10,
                'interval' => '1 hour',

                'cache_pool' => 'cache.calendar_client',
            ],

            /*
             * GET /k/{token}/export/{id} (polled every ~1.5 s while a job
             * runs) and …/download, per link: one row read, or one file of at
             * most 32 MB through PHP (larger ones redirect to the storage).
             */
            'calendar_client_export_read' => [
                'policy' => 'sliding_window',
                'limit' => 3000,
                'interval' => '1 hour',

                'cache_pool' => 'cache.calendar_client',
            ],
        ],
    ],
]);
