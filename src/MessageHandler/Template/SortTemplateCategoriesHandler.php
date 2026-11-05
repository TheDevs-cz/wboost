<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\TemplateCategoryNotFound;
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
        // The voter only checked the project in the URL, so an id that is
        // malformed, unknown or belongs to ANOTHER project is ignored (F12) —
        // the same skip-what-doesn't-fit stance as SortTemplatesHandler.
        foreach ($message->categories as $position => $categoryId) {
            if (!Uuid::isValid($categoryId)) {
                continue;
            }

            try {
                $category = $this->templateCategoryRepository->get(Uuid::fromString($categoryId));
            } catch (TemplateCategoryNotFound) {
                continue;
            }

            if (!$category->project->id->equals($message->projectId)) {
                continue;
            }

            $category->sort($position);
        }
    }
}
