<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;

/**
 * The new order of the manual's "Stránky" list — mockup pages and custom
 * content pages together, one shared position space. Items are typed ids
 * (`mockup:<uuid>` / `content:<uuid>`, see `ManualPageListItem`).
 */
readonly final class SortManualPages
{
    public function __construct(
        /** The manual in the URL — only its own pages are re-sorted. */
        public UuidInterface $manualId,
        /** @var array<mixed> */
        public array $pages,
    ) {
    }
}
