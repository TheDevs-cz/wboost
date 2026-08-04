<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\TemplateCategoryNotFound;
use WBoost\Web\Message\Template\EditTemplateCategory;
use WBoost\Web\Repository\TemplateCategoryRepository;

#[AsMessageHandler]
readonly final class EditTemplateCategoryHandler
{
    public function __construct(
        private TemplateCategoryRepository $templateCategoryRepository,
    ) {
    }

    /**
     * @throws TemplateCategoryNotFound
     */
    public function __invoke(EditTemplateCategory $message): void
    {
        $category = $this->templateCategoryRepository->get($message->categoryId);
        $category->edit($message->name);
    }
}
