<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Flyer;

readonly final class SortFlyerCategories
{
    public function __construct(
        /** @var array<string> */
        public array $categories,
    ) {
    }
}
