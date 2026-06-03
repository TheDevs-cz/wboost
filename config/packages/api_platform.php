<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'api_platform' => [
        'title' => 'WBoost API',
        'version' => '1.0.0',
        'description' => 'WBoost Brand Manuals — service-to-service API.',
        'mapping' => [
            'paths' => [
                '%kernel.project_dir%/src/Api',
            ],
        ],
        'enable_entrypoint' => false,
        'enable_docs' => true,
        'enable_swagger_ui' => true,
        'formats' => [
            'json' => ['mime_types' => ['application/json']],
        ],
        'docs_formats' => [
            'json' => ['mime_types' => ['application/json']],
            'jsonopenapi' => ['mime_types' => ['application/vnd.openapi+json']],
            'html' => ['mime_types' => ['text/html']],
        ],
        'error_formats' => [
            'jsonproblem' => ['mime_types' => ['application/problem+json']],
            'json' => ['mime_types' => ['application/problem+json', 'application/json']],
        ],
        'defaults' => [
            'stateless' => true,
            'cache_headers' => [
                'vary' => ['Authorization', 'Accept'],
            ],
        ],
        'swagger' => [
            'api_keys' => [
                'Bearer' => [
                    'name' => 'Authorization',
                    'type' => 'header',
                ],
            ],
        ],
        'doctrine' => false,
        'doctrine_mongodb_odm' => false,
    ],
]);
