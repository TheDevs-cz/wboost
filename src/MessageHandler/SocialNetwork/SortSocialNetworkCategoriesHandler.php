<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialNetwork;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\SocialNetwork\SortSocialNetworkCategories;
use WBoost\Web\Repository\SocialNetworkCategoryRepository;

#[AsMessageHandler]
readonly final class SortSocialNetworkCategoriesHandler
{
    public function __construct(
        private SocialNetworkCategoryRepository $socialNetworkCategoryRepository,
    ) {
    }

    public function __invoke(SortSocialNetworkCategories $message): void
    {
        foreach ($message->categories as $position => $categoryId) {
            $category = $this->socialNetworkCategoryRepository->get(Uuid::fromString($categoryId));
            $category->sort($position);
        }
    }
}
