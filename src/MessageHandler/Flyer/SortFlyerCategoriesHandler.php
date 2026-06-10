<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Flyer;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Flyer\SortFlyerCategories;
use WBoost\Web\Repository\FlyerCategoryRepository;

#[AsMessageHandler]
readonly final class SortFlyerCategoriesHandler
{
    public function __construct(
        private FlyerCategoryRepository $flyerCategoryRepository,
    ) {
    }

    public function __invoke(SortFlyerCategories $message): void
    {
        foreach ($message->categories as $position => $categoryId) {
            $category = $this->flyerCategoryRepository->get(Uuid::fromString($categoryId));
            $category->sort($position);
        }
    }
}
