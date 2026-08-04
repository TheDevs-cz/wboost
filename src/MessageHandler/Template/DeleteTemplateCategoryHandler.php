<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\TemplateCategoryNotFound;
use WBoost\Web\Message\Template\DeleteTemplateCategory;
use WBoost\Web\Repository\TemplateCategoryRepository;

#[AsMessageHandler]
readonly final class DeleteTemplateCategoryHandler
{
    public function __construct(
        private TemplateCategoryRepository $templateCategoryRepository,
    ) {
    }

    /**
     * @throws TemplateCategoryNotFound
     */
    public function __invoke(DeleteTemplateCategory $message): void
    {
        $category = $this->templateCategoryRepository->get($message->categoryId);

        $this->templateCategoryRepository->remove($category);
    }
}
