<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use AsyncAws\Core\Configuration;
use AsyncAws\S3\S3Client;
use Lustmored\Flysystem\Cache\CacheAdapter;
use Monolog\Level;
use Monolog\Processor\PsrLogMessageProcessor;
use Sentry\Monolog\BreadcrumbHandler as SentryBreadcrumbHandler;
use Sentry\Monolog\Handler as SentryMonologHandler;
use Sentry\State\HubInterface;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;
use WBoost\Web\Services\Doctrine\FixDoctrineMigrationTableSchema;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        ->set('.container.dumper.inline_factories', true)
        ->set('doctrine.orm.enable_lazy_ghost_objects', true)
        ->set('publicAssetsBaseUrl', '%env(UPLOADS_BASE_URL)%/%env(S3_BUCKET_NAME)%')
        // Facebook/Instagram env defaults: prod's .env is rendered from
        // Infisical, so a deploy made before these vars exist there must NOT
        // take the site down (MetaGraphApi resolves them on instantiation,
        // which happens on every authenticated request via the authenticator).
        // With empty credentials the Facebook buttons render but the OAuth
        // dance fails at facebook.com — degraded, not broken.
        ->set('env(FACEBOOK_APP_ID)', '')
        ->set('env(FACEBOOK_APP_SECRET)', '')
        ->set('env(META_GRAPH_BASE_URL)', 'https://graph.facebook.com/v23.0/')
        ->set('env(SOCIAL_TOKEN_ENCRYPTION_KEY)', '');

    $services = $container->services();

    $services->defaults()
        ->autoconfigure()
        ->autowire()
        ->public()
        // Admin recipients for new-registration notifications (csv env -> list<string>).
        ->bind('array $signupNotificationRecipients', '%env(csv:SIGNUP_NOTIFICATION_EMAILS)%');

    $services->set(PdoSessionHandler::class)
        ->args([
            env('DATABASE_URL'),
        ]);

    $services->set(PsrLogMessageProcessor::class)
        ->tag('monolog.processor');

    // Controllers
    $services->load('WBoost\\Web\\Controller\\', __DIR__ . '/../src/Controller/**/{*Controller.php}');

    // Components
    $services->load('WBoost\\Web\\Twig\\Components\\', __DIR__ . '/../src/Twig/Components/**/{*.php}');

    // Repositories
    $services->load('WBoost\\Web\\Repository\\', __DIR__ . '/../src/Repository/{*Repository.php}');

    // Form types
    $services->load('WBoost\\Web\\FormType\\', __DIR__ . '/../src/FormType/**/{*.php}');

    // Message handlers
    $services->load('WBoost\\Web\\MessageHandler\\', __DIR__ . '/../src/MessageHandler/**/{*.php}');

    // Console commands
    $services->load('WBoost\\Web\\ConsoleCommands\\', __DIR__ . '/../src/ConsoleCommands/**/{*.php}');

    // Validators
    $services->load('WBoost\\Web\\Validation\\', __DIR__ . '/../src/Validation/**/{*Validator.php}');

    // Services
    $services->load('WBoost\\Web\\Services\\', __DIR__ . '/../src/Services/**/{*.php}');
    $services->load('WBoost\\Web\\Query\\', __DIR__ . '/../src/Query/**/{*.php}')
        // Read-model DTOs (e.g. UserOverviewRow, the UsageOverview view-model and
        // its UsageMonthMetrics) live next to their query but are plain value
        // objects, not services.
        ->exclude([
            __DIR__ . '/../src/Query/**/*Row.php',
            __DIR__ . '/../src/Query/UsageOverview.php',
            __DIR__ . '/../src/Query/UsageMonthMetrics.php',
            __DIR__ . '/../src/Query/UserActivityOverview.php',
            __DIR__ . '/../src/Query/TemplateGroupListItem.php',
        ]);

    // API Platform State Providers / Processors (DTOs themselves are not services).
    $services->load('WBoost\\Web\\Api\\', __DIR__ . '/../src/Api/**/{*Provider.php,*Processor.php}');

    // Social network template renderer — alias the interface so tests can decorate / replace it.
    $services->alias(
        \WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface::class,
        \WBoost\Web\Services\Editor\TemplateVariantImageRenderer::class,
    );

    // Meta Graph API client — same pattern: tests replace it with a fake.
    $services->alias(
        \WBoost\Web\Services\Meta\MetaGraphApiInterface::class,
        \WBoost\Web\Services\Meta\MetaGraphApi::class,
    );

    /** @see https://github.com/doctrine/migrations/issues/1406 */
    $services->set(FixDoctrineMigrationTableSchema::class)
        ->autoconfigure(false)
        ->arg('$dependencyFactory', service('doctrine.migrations.dependency_factory'))
        ->tag('doctrine.event_listener', ['event' => 'postGenerateSchema']);

    $services->set(S3Client::class)
        ->args([
            '$configuration' => [
                Configuration::OPTION_REGION => env('S3_REGION'),
                Configuration::OPTION_ENDPOINT => env('S3_ENDPOINT'),
                Configuration::OPTION_ACCESS_KEY_ID => env('S3_ACCESS_KEY'),
                Configuration::OPTION_SECRET_ACCESS_KEY => env('S3_SECRET_KEY'),
                Configuration::OPTION_PATH_STYLE_ENDPOINT => true,
            ],
        ]);

    $services->set(SentryMonologHandler::class)
        ->args([
            service(HubInterface::class),
            Level::Error,
            true,
            true,
        ]);

    $services->set(SentryBreadcrumbHandler::class)
        ->args([
            service(HubInterface::class),
            Level::Info,
        ]);

    $services->set('minio.cache.adapter')
        ->class(CacheAdapter::class)
        ->args([
            '$adapter' => service('oneup_flysystem.minio_adapter'),
            '$cachePool' => service('cache.flysystem.psr6'),
        ]);
};
