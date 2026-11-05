<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

use Ramsey\Uuid\UuidInterface;

readonly final class SortTemplateCategories
{
    public function __construct(
        /** The project in the URL — only its categories are re-sorted. */
        public UuidInterface $projectId,
        /** @var array<string> */
        public array $categories,
    ) {
    }
}
