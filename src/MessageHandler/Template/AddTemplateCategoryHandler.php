<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\TemplateCategory;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\Template\AddTemplateCategory;
use WBoost\Web\Repository\TemplateCategoryRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class AddTemplateCategoryHandler
{
    public function __construct(
        private TemplateCategoryRepository $templateCategoryRepository,
        private ProjectRepository $projectRepository,
        private ClockInterface $clock,
        private ProvideIdentity $provideIdentity,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(AddTemplateCategory $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $nextPosition = $this->templateCategoryRepository->count($project->id);

        $category = new TemplateCategory(
            $this->provideIdentity->next(),
            $project,
            $this->clock->now(),
            $message->name,
            $nextPosition,
        );

        $this->templateCategoryRepository->add($category);
    }
}
