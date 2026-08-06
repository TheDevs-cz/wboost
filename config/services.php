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
        ->set('env(SOCIAL_TOKEN_ENCRYPTION_KEY)', '')
        // RFC 7591 dynamic client registration (S8-T4) — OFF unless explicitly
        // turned on. The default lives here as well as in `.env` because prod's
        // `.env` is rendered from Infisical: a deploy made before the variable
        // exists there must fall back to "disabled", never to a container that
        // cannot boot. See config/packages/league_oauth2_server.php for why the
        // safe value is `0` until the consent screen (S8-T5) exists.
        ->set('env(OAUTH2_DYNAMIC_CLIENT_REGISTRATION)', '0');

    $services = $container->services();

    $services->defaults()
        ->autoconfigure()
        ->autowire()
        ->public()
        // Admin recipients for new-registration notifications (csv env -> list<string>).
        ->bind('array $signupNotificationRecipients', '%env(csv:SIGNUP_NOTIFICATION_EMAILS)%')
        // OAuth2 grants this authorization server offers — the SAME list that
        // drives the bundle's `enable_*_grant` switches, so the RFC 8414
        // metadata document cannot advertise a grant that is turned off (or
        // miss one that was turned on). Defined in
        // `config/packages/league_oauth2_server.php`.
        ->bind('array $grantTypesSupported', '%oauth2.grant_types_supported%')
        // Whether `POST /api/register` answers and whether the RFC 8414
        // document advertises it — one flag, two consumers, so they cannot
        // disagree. Defined in config/packages/league_oauth2_server.php, where
        // the reason it defaults to OFF is written down.
        ->bind('bool $dynamicClientRegistrationEnabled', '%oauth2.dynamic_client_registration_enabled%');

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
            __DIR__ . '/../src/Query/StorageOverview.php',
            __DIR__ . '/../src/Query/StorageFilesPage.php',
            __DIR__ . '/../src/Query/ProjectTemplateStats.php',
            __DIR__ . '/../src/Query/TemplateDimensionUsage.php',
        ]);

    // API Platform State Providers / Processors (DTOs themselves are not services).
    $services->load('WBoost\\Web\\Api\\', __DIR__ . '/../src/Api/**/{*Provider.php,*Processor.php}');

    // MCP server: tool classes, the shared fill resolution, the design pipeline
    // and the token security layer.
    // Same rule as src/Api/ above — response DTOs, the parsed DSL value objects and
    // the exceptions are plain values, never services. The directory list is an
    // explicit allow-list (anything else under src/Mcp/ is wired by hand); the
    // exclude list states the DTO rule out loud and is load-bearing for Design/Dsl,
    // which sits inside a loaded directory.
    $services->load('WBoost\\Web\\Mcp\\', __DIR__ . '/../src/Mcp/{Tool,Fill,Design,Security}/**/*.php')
        ->exclude([
            __DIR__ . '/../src/Mcp/Response/**/*.php',
            __DIR__ . '/../src/Mcp/Design/Dsl/**/*.php',
            __DIR__ . '/../src/Mcp/Exception/**/*.php',
        ]);

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

    // OAuth2 resource owner identity (S8-T1): override the bundle's converter so
    // an authorization-code token's `sub` is the App User UUID — the identifier
    // `api_user_provider` (an entity provider on the `id` column) can actually
    // load, and the one IssueAccessTokenWithUserListener already writes for the
    // client_credentials grant.
    $services->alias(
        \League\Bundle\OAuth2ServerBundle\Converter\UserConverterInterface::class,
        \WBoost\Web\Services\OAuth2\AppUserConverter::class,
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
