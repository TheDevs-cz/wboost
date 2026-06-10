<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Flyer;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\FlyerCategory;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\Flyer\AddFlyerCategory;
use WBoost\Web\Repository\FlyerCategoryRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class AddFlyerCategoryHandler
{
    public function __construct(
        private FlyerCategoryRepository $flyerCategoryRepository,
        private ProjectRepository $projectRepository,
        private ClockInterface $clock,
        private ProvideIdentity $provideIdentity,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(AddFlyerCategory $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $nextPosition = $this->flyerCategoryRepository->count($project->id);

        $category = new FlyerCategory(
            $this->provideIdentity->next(),
            $project,
            $this->clock->now(),
            $message->name,
            $nextPosition,
        );

        $this->flyerCategoryRepository->add($category);
    }
}
