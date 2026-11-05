<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;

readonly final class SortManualMockupPages
{
    public function __construct(
        /** The manual in the URL — only its mockup pages are re-sorted. */
        public UuidInterface $manualId,
        /** @var array<string> */
        public array $pages,
    ) {
    }
}
