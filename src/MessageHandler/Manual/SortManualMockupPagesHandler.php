<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualMockupPageNotFound;
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
        // The voter only checked the manual in the URL, so an id that is
        // malformed, unknown or belongs to ANOTHER manual is ignored (F12
        // follow-up) — same skip-what-doesn't-fit stance as the category sort.
        foreach ($message->pages as $position => $pageId) {
            if (!Uuid::isValid($pageId)) {
                continue;
            }

            try {
                $page = $this->manualMockupPageRepository->get(Uuid::fromString($pageId));
            } catch (ManualMockupPageNotFound) {
                continue;
            }

            if (!$page->manual->id->equals($message->manualId)) {
                continue;
            }

            $page->sort($position);
        }
    }
}
