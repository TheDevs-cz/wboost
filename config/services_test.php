<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->autoconfigure()
        ->autowire()
        ->public();

    // Data fixtures
    $services->load('WBoost\\Web\\Tests\\DataFixtures\\', __DIR__ . '/../tests/DataFixtures/{*.php}');

    // Test fakes — replace the social-network image renderer with a deterministic 1×1 PNG
    // emitter so tests don't depend on Gotenberg / Minio / project fonts.
    $services->load('WBoost\\Web\\Tests\\Fakes\\', __DIR__ . '/../tests/Fakes/{*.php}');
    $services->alias(
        \WBoost\Web\Services\SocialNetwork\SocialNetworkTemplateVariantImageRendererInterface::class,
        \WBoost\Web\Tests\Fakes\FakeSocialNetworkTemplateVariantImageRenderer::class,
    );

    // Object store: a LOCAL directory instead of Minio (S3). Placeholder-image
    // tests read/write real bytes (inline for dimensions, upload, preview), and
    // CI's test runner can't resolve the `minio` host ("Could not resolve host:
    // minio"). AssetInliner + the upload/preview handlers all resolve
    // `oneup_flysystem.minio_filesystem`, so overriding it here keeps every
    // filesystem touch network-free.
    //
    // Root is OUTSIDE the workspace (/tmp), NOT under var/cache: the CI cache
    // step's key is hashFiles('**/composer.lock'), which traverses the whole
    // workspace in its post-run — image bytes written under var/cache make that
    // traversal fail ("Fail to hash files under directory").
    $services->set('oneup_flysystem.minio_filesystem', Filesystem::class)
        ->args([inline_service(LocalFilesystemAdapter::class)->args(['/tmp/wboost-test-uploads'])])
        ->autowire(false)
        ->autoconfigure(false)
        ->public();
};
