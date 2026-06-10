<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Flyer;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FlyerCategoryNotFound;
use WBoost\Web\Message\Flyer\DeleteFlyerCategory;
use WBoost\Web\Repository\FlyerCategoryRepository;

#[AsMessageHandler]
readonly final class DeleteFlyerCategoryHandler
{
    public function __construct(
        private FlyerCategoryRepository $flyerCategoryRepository,
    ) {
    }

    /**
     * @throws FlyerCategoryNotFound
     */
    public function __invoke(DeleteFlyerCategory $message): void
    {
        $category = $this->flyerCategoryRepository->get($message->categoryId);

        $this->flyerCategoryRepository->remove($category);
    }
}
