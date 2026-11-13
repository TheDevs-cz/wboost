<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'router' => [
            'utf8' => true,
            // The host of links generated where no request exists — the
            // messenger consumer (the e-mail about an ended scan) and cron
            // commands (`app:ai-scan:purge`, `app:ai-scan:watch`). Without it
            // such a link is `http://localhost/…`. A request always wins.
            'default_uri' => '%env(DEFAULT_URI)%',
        ],
    ],
]);
