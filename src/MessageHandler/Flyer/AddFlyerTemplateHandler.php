<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Flyer;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\FlyerTemplate;
use WBoost\Web\Exceptions\FlyerCategoryNotFound;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\Flyer\AddFlyerTemplate;
use WBoost\Web\Repository\FlyerCategoryRepository;
use WBoost\Web\Repository\FlyerTemplateRepository;
use WBoost\Web\Repository\ProjectRepository;

#[AsMessageHandler]
readonly final class AddFlyerTemplateHandler
{
    public function __construct(
        private FlyerTemplateRepository $flyerTemplateRepository,
        private FlyerCategoryRepository $flyerCategoryRepository,
        private ProjectRepository $projectRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws ProjectNotFound
     * @throws FlyerCategoryNotFound
     */
    public function __invoke(AddFlyerTemplate $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $templateId = $message->templateId;

        $imagePath = null;
        $image = $message->image;

        if ($image !== null) {
            $timestamp = $this->clock->now()->getTimestamp();

            $extension = $image->guessExtension();
            $imagePath = "flyers/templates/$message->templateId/image-$timestamp.$extension";
            $this->filesystem->write($imagePath, $image->getContent());
        }

        $nextPosition = $this->flyerTemplateRepository->count($project->id);
        $category = $message->categoryId !== null
            ? $this->flyerCategoryRepository->get($message->categoryId)
            : null;

        $template = new FlyerTemplate(
            $templateId,
            $project,
            $category,
            $this->clock->now(),
            $message->name,
            $imagePath,
            $nextPosition,
        );

        $this->flyerTemplateRepository->add($template);
    }
}
