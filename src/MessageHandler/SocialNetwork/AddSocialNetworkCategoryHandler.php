<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialNetwork;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\SocialNetworkCategory;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\SocialNetwork\AddSocialNetworkCategory;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\SocialNetworkCategoryRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class AddSocialNetworkCategoryHandler
{
    public function __construct(
        private SocialNetworkCategoryRepository $socialNetworkCategoryRepository,
        private ProjectRepository $projectRepository,
        private ClockInterface $clock,
        private ProvideIdentity $provideIdentity,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(AddSocialNetworkCategory $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $nextPosition = $this->socialNetworkCategoryRepository->count($project->id);

        $category = new SocialNetworkCategory(
            $this->provideIdentity->next(),
            $project,
            $this->clock->now(),
            $message->name,
            $nextPosition,
        );

        $this->socialNetworkCategoryRepository->add($category);
    }
}
