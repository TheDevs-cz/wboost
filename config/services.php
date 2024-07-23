<?php

declare(strict_types=1);

use AsyncAws\Core\Configuration;
use AsyncAws\S3\S3Client;
use Monolog\Processor\PsrLogMessageProcessor;
use BrandManuals\Web\Services\SentryApiClient;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;
use function Symfony\Component\DependencyInjection\Loader\Configurator\env;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function(ContainerConfigurator $configurator): void
{
    $parameters = $configurator->parameters();

    # https://symfony.com/doc/current/performance.html#dump-the-service-container-into-a-single-file
    $parameters->set('.container.dumper.inline_factories', true);

    $parameters->set('doctrine.orm.enable_lazy_ghost_objects', true);

    $parameters->set('publicAssetsBaseUrl', '%env(UPLOADS_BASE_URL)%/%env(S3_BUCKET_NAME)%');
    $parameters->set('internalAssetsBaseUrl', '%env(S3_ENDPOINT)%/%env(S3_BUCKET_NAME)%');

    $services = $configurator->services();

    $services->defaults()
        ->autoconfigure()
        ->autowire()
        ->public();

    $services->set(PdoSessionHandler::class)
        ->args([
            env('DATABASE_URL'),
        ]);

    $services->set(PsrLogMessageProcessor::class)
        ->tag('monolog.processor');

    // Controllers
    $services->load('BrandManuals\\Web\\Controller\\', __DIR__ . '/../src/Controller/{*Controller.php}');

    // Twig extensions
    $services->load('BrandManuals\\Web\\Twig\\', __DIR__ . '/../src/Twig/{*TwigExtension.php}');

    // Repositories
    $services->load('BrandManuals\\Web\\Repository\\', __DIR__ . '/../src/Repository/{*Repository.php}');

    // Form types
    $services->load('BrandManuals\\Web\\FormType\\', __DIR__ . '/../src/FormType/**/{*.php}');

    // Message handlers
    $services->load('BrandManuals\\Web\\MessageHandler\\', __DIR__ . '/../src/MessageHandler/**/{*.php}');

    // Console commands
    $services->load('BrandManuals\\Web\\ConsoleCommands\\', __DIR__ . '/../src/ConsoleCommands/**/{*.php}');

    // Services
    $services->load('BrandManuals\\Web\\Services\\', __DIR__ . '/../src/Services/**/{*.php}');
    $services->load('BrandManuals\\Web\\Query\\', __DIR__ . '/../src/Query/**/{*.php}');

    $services->set(S3Client::class)
        ->args([
            '$configuration' => [
                Configuration::OPTION_REGION => env('S3_REGION'),
                Configuration::OPTION_ENDPOINT => env('S3_ENDPOINT'),
                Configuration::OPTION_ACCESS_KEY_ID => env('S3_ACCESS_KEY'),
                Configuration::OPTION_SECRET_ACCESS_KEY => env('S3_SECRET_KEY'),
                Configuration::OPTION_PATH_STYLE_ENDPOINT => true,
            ]
        ]);

    $services->set('minio.cache.adapter')
        ->class(Lustmored\Flysystem\Cache\CacheAdapter::class)
        ->args([
            '$adapter' => service('oneup_flysystem.minio_adapter'),
            '$cachePool' => service('cache.flysystem.psr6'),
        ]);
};
