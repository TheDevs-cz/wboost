<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'form' => [
            'csrf_protection' => [
                'token_id' => 'submit',
            ],
        ],
        'csrf_protection' => [
            // `ai_scan`: the scan surface's JSON POSTs (start, cancel, retry, …)
            // are same-origin fetches — validated by the Origin header, with
            // `_token=csrf-token`, no session token to plumb into scripts.
            'stateless_token_ids' => ['submit', 'authenticate', 'logout', 'ai_scan'],
        ],
    ],
]);
