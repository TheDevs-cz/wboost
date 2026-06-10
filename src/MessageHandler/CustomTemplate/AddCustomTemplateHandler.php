<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\CustomTemplate;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\CustomTemplate;
use WBoost\Web\Exceptions\CustomTemplateCategoryNotFound;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\CustomTemplate\AddCustomTemplate;
use WBoost\Web\Repository\CustomTemplateCategoryRepository;
use WBoost\Web\Repository\CustomTemplateRepository;
use WBoost\Web\Repository\ProjectRepository;

#[AsMessageHandler]
readonly final class AddCustomTemplateHandler
{
    public function __construct(
        private CustomTemplateRepository $customTemplateRepository,
        private CustomTemplateCategoryRepository $customTemplateCategoryRepository,
        private ProjectRepository $projectRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws ProjectNotFound
     * @throws CustomTemplateCategoryNotFound
     */
    public function __invoke(AddCustomTemplate $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $templateId = $message->templateId;

        $imagePath = null;
        $image = $message->image;

        if ($image !== null) {
            $timestamp = $this->clock->now()->getTimestamp();

            $extension = $image->guessExtension();
            $imagePath = "custom-templates/templates/$message->templateId/image-$timestamp.$extension";
            $this->filesystem->write($imagePath, $image->getContent());
        }

        $nextPosition = $this->customTemplateRepository->count($project->id);
        $category = $message->categoryId !== null
            ? $this->customTemplateCategoryRepository->get($message->categoryId)
            : null;

        $template = new CustomTemplate(
            $templateId,
            $project,
            $category,
            $this->clock->now(),
            $message->name,
            $imagePath,
            $nextPosition,
        );

        $this->customTemplateRepository->add($template);
    }
}
