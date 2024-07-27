<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Project;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\Project\AddImageColorsToProject;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\DetectImageColors;
use WBoost\Web\Services\UploaderHelper;

#[AsMessageHandler]
readonly final class AddImageColorsToProjectHandler
{
    public function __construct(
        private DetectImageColors $detectImageColors,
        private ProjectRepository $projectRepository,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(AddImageColorsToProject $message): void
    {
        $project = $this->projectRepository->get($message->projectId);

        $colors = $this->detectImageColors->fromImagePath(
            $this->uploaderHelper->getInternalPath($message->imagePath),
        );

        $project->addColors($colors);
    }
}
