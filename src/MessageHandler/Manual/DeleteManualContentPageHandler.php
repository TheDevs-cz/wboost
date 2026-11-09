<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\IntroContentPageCannotBeDeleted;
use WBoost\Web\Exceptions\ManualContentPageNotFound;
use WBoost\Web\Message\Manual\DeleteManualContentPage;
use WBoost\Web\Repository\ManualContentPageRepository;

/**
 * The page's uploaded pictures stay in storage (like a mockup page's): the
 * storage scan reports them as orphans once nothing references them.
 */
#[AsMessageHandler]
readonly final class DeleteManualContentPageHandler
{
    public function __construct(
        private ManualContentPageRepository $contentPageRepository,
    ) {
    }

    /**
     * @throws ManualContentPageNotFound
     * @throws IntroContentPageCannotBeDeleted
     */
    public function __invoke(DeleteManualContentPage $message): void
    {
        $page = $this->contentPageRepository->get($message->pageId);

        if ($page->isIntro()) {
            throw new IntroContentPageCannotBeDeleted();
        }

        $this->contentPageRepository->remove($page);
    }
}
