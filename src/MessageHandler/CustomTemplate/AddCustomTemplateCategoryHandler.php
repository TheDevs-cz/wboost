<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\CustomTemplate;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\CustomTemplateCategory;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\CustomTemplate\AddCustomTemplateCategory;
use WBoost\Web\Repository\CustomTemplateCategoryRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class AddCustomTemplateCategoryHandler
{
    public function __construct(
        private CustomTemplateCategoryRepository $customTemplateCategoryRepository,
        private ProjectRepository $projectRepository,
        private ClockInterface $clock,
        private ProvideIdentity $provideIdentity,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(AddCustomTemplateCategory $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $nextPosition = $this->customTemplateCategoryRepository->count($project->id);

        $category = new CustomTemplateCategory(
            $this->provideIdentity->next(),
            $project,
            $this->clock->now(),
            $message->name,
            $nextPosition,
        );

        $this->customTemplateCategoryRepository->add($category);
    }
}
