<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualMockupPageNotFound;
use WBoost\Web\Message\Manual\SortManualPages;
use WBoost\Web\Repository\ManualContentPageRepository;
use WBoost\Web\Repository\ManualMockupPageRepository;
use WBoost\Web\Value\ManualPageListItem;

/**
 * Writes the shared position space of mockup pages + custom content pages
 * (both tables in the bus' one Doctrine transaction): an item's position is
 * its index in the posted list.
 */
#[AsMessageHandler]
readonly final class SortManualPagesHandler
{
    public function __construct(
        private ManualMockupPageRepository $mockupPageRepository,
        private ManualContentPageRepository $contentPageRepository,
    ) {
    }

    public function __invoke(SortManualPages $message): void
    {
        // The voter only checked the manual in the URL, so an id that is
        // malformed, unknown, belongs to ANOTHER manual or is the intro
        // (pinned first, not part of the list) is skipped.
        foreach (array_values($message->pages) as $position => $typedId) {
            $parsed = is_string($typedId) ? ManualPageListItem::parseTypedId($typedId) : null;

            if ($parsed === null) {
                continue;
            }

            [$kind, $id] = $parsed;

            if ($kind === ManualPageListItem::CONTENT) {
                $page = $this->contentPageRepository->find($id);

                if ($page !== null && !$page->isIntro() && $page->manual->id->equals($message->manualId)) {
                    $page->sort($position);
                }

                continue;
            }

            try {
                $page = $this->mockupPageRepository->get($id);
            } catch (ManualMockupPageNotFound) {
                continue;
            }

            if ($page->manual->id->equals($message->manualId)) {
                $page->sort($position);
            }
        }
    }
}
