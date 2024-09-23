<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Manual\SortManualMockupPages;
use WBoost\Web\Repository\ManualMockupPageRepository;

#[AsMessageHandler]
readonly final class SortManualMockupPagesHandler
{
    public function __construct(
        private ManualMockupPageRepository $manualMockupPageRepository,
    ) {
    }

    public function __invoke(SortManualMockupPages $message): void
    {
        foreach ($message->pages as $position => $pageId) {
            $page = $this->manualMockupPageRepository->get(Uuid::fromString($pageId));
            $page->sort($position);
        }
    }
}
