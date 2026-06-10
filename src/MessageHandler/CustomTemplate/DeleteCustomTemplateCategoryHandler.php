<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\CustomTemplate;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\CustomTemplateCategoryNotFound;
use WBoost\Web\Message\CustomTemplate\DeleteCustomTemplateCategory;
use WBoost\Web\Repository\CustomTemplateCategoryRepository;

#[AsMessageHandler]
readonly final class DeleteCustomTemplateCategoryHandler
{
    public function __construct(
        private CustomTemplateCategoryRepository $customTemplateCategoryRepository,
    ) {
    }

    /**
     * @throws CustomTemplateCategoryNotFound
     */
    public function __invoke(DeleteCustomTemplateCategory $message): void
    {
        $category = $this->customTemplateCategoryRepository->get($message->categoryId);

        $this->customTemplateCategoryRepository->remove($category);
    }
}
