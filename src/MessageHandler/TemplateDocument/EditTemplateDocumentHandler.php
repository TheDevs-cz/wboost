<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\TemplateDocument;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\TemplateDocumentNotFound;
use WBoost\Web\Message\TemplateDocument\EditTemplateDocument;
use WBoost\Web\Repository\TemplateCategoryRepository;
use WBoost\Web\Repository\TemplateDocumentRepository;

#[AsMessageHandler]
readonly final class EditTemplateDocumentHandler
{
    public function __construct(
        private TemplateDocumentRepository $documentRepository,
        private TemplateCategoryRepository $categoryRepository,
    ) {
    }

    /**
     * @throws TemplateDocumentNotFound
     */
    public function __invoke(EditTemplateDocument $message): void
    {
        $document = $this->documentRepository->get($message->documentId);
        $category = $message->categoryId !== null ? $this->categoryRepository->get($message->categoryId) : null;

        if ($category !== null && !$category->project->id->equals($document->project->id)) {
            $category = null;
        }

        $document->edit($category, $message->name);
    }
}
