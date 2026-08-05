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
use WBoost\Web\Services\ProjectIconUploader;

#[AsMessageHandler]
readonly final class AddProjectHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private UserRepository $userRepository,
        private ClockInterface $clock,
        private ProjectIconUploader $iconUploader,
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

        if ($message->icon !== null) {
            $project->changeIcon(
                $this->iconUploader->upload($message->projectId, $message->icon),
            );
        }

        $this->projectRepository->add($project);
    }
}
