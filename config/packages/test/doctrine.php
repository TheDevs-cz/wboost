<?php

declare(strict_types=1);

use Ramsey\Uuid\Doctrine\UuidType;
use BrandManuals\Web\Doctrine\LapsArrayDoctrineType;
use BrandManuals\Web\Doctrine\PuzzlersGroupDoctrineType;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('doctrine', [
        'dbal' => [
            'use_savepoints' => true,
        ],
    ]);
};
