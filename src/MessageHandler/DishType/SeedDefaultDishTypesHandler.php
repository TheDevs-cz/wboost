<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\DishType;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\DishType;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\DishType\SeedDefaultDishTypes;
use WBoost\Web\Query\GetDishTypes;
use WBoost\Web\Repository\DishTypeRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class SeedDefaultDishTypesHandler
{
    private const array DEFAULT_DISH_TYPES = [
        'Polévka',
        'Hlavní jídlo',
        'Večeře',
        'Pečivo',
        'Nápoj',
        'Uzeniny',
        'Sýry',
        'Dezert / Kompot',
    ];

    public function __construct(
        private ProjectRepository $projectRepository,
        private DishTypeRepository $dishTypeRepository,
        private GetDishTypes $getDishTypes,
        private ProvideIdentity $provideIdentity,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(SeedDefaultDishTypes $message): void
    {
        $project = $this->projectRepository->get($message->projectId);

        $existingCount = $this->getDishTypes->countForProject($message->projectId);

        if ($existingCount > 0) {
            return;
        }

        $position = 0;
        foreach (self::DEFAULT_DISH_TYPES as $name) {
            $dishType = new DishType(
                $this->provideIdentity->next(),
                $project,
                $name,
                $position,
            );

            $this->dishTypeRepository->add($dishType);
            $position++;
        }
    }
}
