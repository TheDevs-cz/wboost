<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'asset_mapper' => [
            'paths' => [
                'assets/',
            ],
            'excluded_patterns' => [
                // The Fabric v7 UMD bundle is committed for the Gotenberg renderer
                // to inline at PNG-export time (see TemplateVariantImageRenderer).
                // It is NOT served to browsers — the editor uses the importmap'd ESM build.
                '*/fabric/fabric-*.min.js',
            ],
        ],
    ],
]);
