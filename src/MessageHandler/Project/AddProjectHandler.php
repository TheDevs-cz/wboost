<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Project;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Message\Project\AddProject;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\UserRepository;

#[AsMessageHandler]
readonly final class AddProjectHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private UserRepository $userRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws UserNotFound
     */
    public function __invoke(AddProject $message): void
    {
        $user = $this->userRepository->get($message->ownerEmail);

        $project = new Project(
            $message->projectId,
            $user,
            $this->clock->now(),
            $message->name,
        );

        $this->projectRepository->add($project);
    }
}
