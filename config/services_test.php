<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

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
};
