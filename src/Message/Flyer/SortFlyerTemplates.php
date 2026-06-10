<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Flyer;

readonly final class SortFlyerTemplates
{
    public function __construct(
        /** @var array<string> */
        public array $templates,
    ) {
    }
}
