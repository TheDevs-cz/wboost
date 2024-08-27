<?php

declare(strict_types=1);

use Ramsey\Uuid\Doctrine\UuidType;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use WBoost\Web\Doctrine\ColorsMappingDoctrineType;
use WBoost\Web\Doctrine\EditorTextInputsDoctrineType;
use WBoost\Web\Doctrine\FontFacesDoctrineType;
use WBoost\Web\Doctrine\LogoDoctrineType;
use WBoost\Web\Doctrine\ProjectSharingDoctrineType;
use WBoost\Web\Doctrine\SocialNetworkTemplateVariantsDoctrineType;

return static function (ContainerConfigurator $containerConfigurator): void {

    $containerConfigurator->extension('doctrine', [
        'dbal' => [
            'url' => '%env(resolve:DATABASE_URL)%',
            'types' => [
                UuidType::NAME => UuidType::class,
                FontFacesDoctrineType::NAME => FontFacesDoctrineType::class,
                LogoDoctrineType::NAME => LogoDoctrineType::class,
                ColorsMappingDoctrineType::NAME => ColorsMappingDoctrineType::class,
                ProjectSharingDoctrineType::NAME => ProjectSharingDoctrineType::class,
                EditorTextInputsDoctrineType::NAME => EditorTextInputsDoctrineType::class,
            ],
        ],
        'orm' => [
            'report_fields_where_declared' => true,
            'auto_generate_proxy_classes' => true,
            'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
            'auto_mapping' => true,
            'controller_resolver' => [
                'auto_mapping' => false,
            ],
            'mappings' => [
                'WBoost' => [
                    'type' => 'attribute',
                    'dir' => '%kernel.project_dir%/src/Entity',
                    'prefix' => 'WBoost\\Web\\Entity',
                ],
            ],
        ],
    ]);
};
