<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Template\SortTemplateCategories;
use WBoost\Web\Repository\TemplateCategoryRepository;

#[AsMessageHandler]
readonly final class SortTemplateCategoriesHandler
{
    public function __construct(
        private TemplateCategoryRepository $templateCategoryRepository,
    ) {
    }

    public function __invoke(SortTemplateCategories $message): void
    {
        foreach ($message->categories as $position => $categoryId) {
            $category = $this->templateCategoryRepository->get(Uuid::fromString($categoryId));
            $category->sort($position);
        }
    }
}
