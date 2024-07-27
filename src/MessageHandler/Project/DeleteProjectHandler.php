<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Project;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\Project\DeleteProject;
use WBoost\Web\Repository\ProjectRepository;

#[AsMessageHandler]
readonly final class DeleteProjectHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(DeleteProject $message): void
    {
        $project = $this->projectRepository->get($message->projectId);

        $this->projectRepository->remove($project);
    }
}
