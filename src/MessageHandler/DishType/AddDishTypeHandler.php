<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\DishType;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\DishType;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\DishType\AddDishType;
use WBoost\Web\Repository\DishTypeRepository;
use WBoost\Web\Repository\ProjectRepository;

#[AsMessageHandler]
readonly final class AddDishTypeHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private DishTypeRepository $dishTypeRepository,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(AddDishType $message): void
    {
        $project = $this->projectRepository->get($message->projectId);

        $dishType = new DishType(
            $message->dishTypeId,
            $project,
            $message->name,
        );

        $this->dishTypeRepository->add($dishType);
    }
}
