<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\CustomTemplate;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\CustomTemplate\SortCustomTemplateCategories;
use WBoost\Web\Repository\CustomTemplateCategoryRepository;

#[AsMessageHandler]
readonly final class SortCustomTemplateCategoriesHandler
{
    public function __construct(
        private CustomTemplateCategoryRepository $customTemplateCategoryRepository,
    ) {
    }

    public function __invoke(SortCustomTemplateCategories $message): void
    {
        foreach ($message->categories as $position => $categoryId) {
            $category = $this->customTemplateCategoryRepository->get(Uuid::fromString($categoryId));
            $category->sort($position);
        }
    }
}
