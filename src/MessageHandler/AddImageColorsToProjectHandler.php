<?php

declare(strict_types=1);

namespace BrandManuals\Web\MessageHandler;

use BrandManuals\Web\Exceptions\ProjectNotFound;
use BrandManuals\Web\Message\AddImageColorsToProject;
use BrandManuals\Web\Repository\ProjectRepository;
use BrandManuals\Web\Services\DetectImageColors;
use BrandManuals\Web\Services\UploaderHelper;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddImageColorsToProjectHandler
{
    public function __construct(
        private DetectImageColors $detectImageColors,
        private ProjectRepository $projectRepository,
        private UploaderHelper $uploaderHelper,
    )
    {
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
