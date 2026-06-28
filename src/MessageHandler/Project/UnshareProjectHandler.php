<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Project;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Message\Project\UnshareProject;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\UserRepository;

#[AsMessageHandler]
readonly final class UnshareProjectHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private UserRepository $userRepository,
    ) {
    }

    /**
     * @throws ProjectNotFound
     * @throws UserNotFound
     */
    public function __invoke(UnshareProject $message): void
    {
        $project = $this->projectRepository->get(Uuid::fromString($message->projectId));
        $user = $this->userRepository->getById(Uuid::fromString($message->userId));

        $project->unshare($user);
    }
}
