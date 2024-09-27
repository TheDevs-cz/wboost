<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialNetwork;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\SocialNetworkCategoryNotFound;
use WBoost\Web\Message\SocialNetwork\DeleteSocialNetworkCategory;
use WBoost\Web\Repository\SocialNetworkCategoryRepository;

#[AsMessageHandler]
readonly final class DeleteSocialNetworkCategoryHandler
{
    public function __construct(
        private SocialNetworkCategoryRepository $socialNetworkCategoryRepository,
    ) {
    }

    /**
     * @throws SocialNetworkCategoryNotFound
     */
    public function __invoke(DeleteSocialNetworkCategory $message): void
    {
        $category = $this->socialNetworkCategoryRepository->get($message->categoryId);

        $this->socialNetworkCategoryRepository->remove($category);
    }
}
