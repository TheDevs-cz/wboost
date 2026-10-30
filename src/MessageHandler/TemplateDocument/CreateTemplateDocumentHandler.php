<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\TemplateDocument;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Exceptions\InvalidTemplateDocumentChange;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\TemplateDocument\CreateTemplateDocument;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\TemplateCategoryRepository;
use WBoost\Web\Repository\TemplateDocumentRepository;
use WBoost\Web\Repository\TemplateRepository;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentPageList;

#[AsMessageHandler]
readonly final class CreateTemplateDocumentHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private TemplateCategoryRepository $categoryRepository,
        private TemplateDocumentRepository $documentRepository,
        private TemplateRepository $templateRepository,
        private TemplateDocumentPageList $pageList,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ProjectNotFound
     * @throws InvalidTemplateDocumentChange
     */
    public function __invoke(CreateTemplateDocument $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $category = $message->categoryId !== null ? $this->categoryRepository->get($message->categoryId) : null;

        if ($category !== null && !$category->project->id->equals($project->id)) {
            $category = null;
        }

        $document = new TemplateDocument(
            $message->documentId,
            $project,
            $category,
            $this->clock->now(),
            $message->name,
            $message->dimension,
            $this->templateRepository->nextPosition($project->id),
        );

        $this->documentRepository->add($document);
        $this->pageList->set($document, $message->templateIds);
    }
}
