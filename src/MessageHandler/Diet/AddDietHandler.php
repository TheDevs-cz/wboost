<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Diet;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Diet;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\Diet\AddDiet;
use WBoost\Web\Repository\DietRepository;
use WBoost\Web\Repository\ProjectRepository;

#[AsMessageHandler]
readonly final class AddDietHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private DietRepository $dietRepository,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(AddDiet $message): void
    {
        $project = $this->projectRepository->get($message->projectId);

        $diet = new Diet(
            $message->dietId,
            $project,
            $message->name,
            $message->codes,
        );

        $this->dietRepository->add($diet);
    }
}
