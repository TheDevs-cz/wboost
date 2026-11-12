<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use AsyncAws\S3\S3Client;
use League\Flysystem\Filesystem;

return App::config([
    'oneup_flysystem' => [
        'adapters' => [
            'cached' => [
                'custom' => [
                    'service' => 'minio.cache.adapter',
                ],
            ],
            'minio' => [
                'async_aws_s3' => [
                    'client' => S3Client::class,
                    'bucket' => 'wboost',
                ],
            ],
            // AI scan files (docs/architecture/ai-scan.md § "Storage"): a
            // SECOND bucket with no anonymous policy. Nothing in it is ever
            // addressed by a URL — bytes leave through controllers only.
            'private' => [
                'async_aws_s3' => [
                    'client' => S3Client::class,
                    'bucket' => '%env(S3_PRIVATE_BUCKET_NAME)%',
                ],
            ],
        ],
        'filesystems' => [
            'minio' => [
                'adapter' => 'minio',
                'alias' => Filesystem::class,
                'visibility' => 'public',
                'directory_visibility' => 'public',
            ],
            'cached' => [
                'adapter' => 'cached',
            ],
            'private' => [
                'adapter' => 'private',
                'visibility' => 'private',
                'directory_visibility' => 'private',
            ],
        ],
    ],
]);
