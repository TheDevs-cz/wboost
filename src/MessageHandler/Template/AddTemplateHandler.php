<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Template;
use WBoost\Web\Exceptions\TemplateCategoryNotFound;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\Template\AddTemplate;
use WBoost\Web\Repository\TemplateCategoryRepository;
use WBoost\Web\Repository\TemplateRepository;
use WBoost\Web\Repository\ProjectRepository;

#[AsMessageHandler]
readonly final class AddTemplateHandler
{
    public function __construct(
        private TemplateRepository $templateRepository,
        private TemplateCategoryRepository $templateCategoryRepository,
        private ProjectRepository $projectRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws ProjectNotFound
     * @throws TemplateCategoryNotFound
     */
    public function __invoke(AddTemplate $message): void
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

        $nextPosition = $this->templateRepository->count($project->id);
        $category = $message->categoryId !== null
            ? $this->templateCategoryRepository->get($message->categoryId)
            : null;

        $template = new Template(
            $templateId,
            $project,
            $category,
            $this->clock->now(),
            $message->name,
            $imagePath,
            $nextPosition,
        );

        $this->templateRepository->add($template);
    }
}
