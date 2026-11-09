<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Repository\ManualContentPageRepository;
use WBoost\Web\Value\ManualPageListItem;

/**
 * The manual's sortable pages — mockup pages and custom content pages in
 * their shared order (admin "Stránky" list, the public manual after the
 * built-in pages). Ties (none after `Version20261109170000`, which
 * renumbered the mockup positions) fall back to creation time, then id —
 * the same tie-break that migration used, so the order never jumps.
 */
readonly final class GetManualPageList
{
    public function __construct(
        private GetManualMockupPages $getManualMockupPages,
        private ManualContentPageRepository $contentPageRepository,
    ) {
    }

    /**
     * @return list<ManualPageListItem>
     */
    public function forManual(UuidInterface $manualId): array
    {
        $items = [];

        foreach ($this->getManualMockupPages->allForManual($manualId) as $page) {
            $items[] = ManualPageListItem::mockup($page);
        }

        foreach ($this->contentPageRepository->customFor($manualId) as $page) {
            $items[] = ManualPageListItem::content($page);
        }

        usort($items, static fn (ManualPageListItem $a, ManualPageListItem $b): int => [$a->position(), $a->createdAt(), $a->id()->toString()]
            <=> [$b->position(), $b->createdAt(), $b->id()->toString()]);

        return $items;
    }
}
