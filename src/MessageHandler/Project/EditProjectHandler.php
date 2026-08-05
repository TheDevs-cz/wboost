<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Project;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\Project\EditProject;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\ProjectIconUploader;

#[AsMessageHandler]
readonly final class EditProjectHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private ProjectIconUploader $iconUploader,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(EditProject $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $iconPath = $project->icon;

        if ($message->icon !== null) {
            $iconPath = $this->iconUploader->upload($project->id, $message->icon);
        } elseif ($message->removeIcon) {
            $iconPath = null;
        }

        if ($iconPath !== $project->icon) {
            $this->iconUploader->delete($project->icon);
        }

        $project->edit($message->name);
        $project->changeIcon($iconPath);
    }
}
