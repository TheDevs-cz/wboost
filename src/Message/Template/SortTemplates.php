<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

readonly final class SortTemplates
{
    public function __construct(
        /** @var array<string> */
        public array $templates,
    ) {
    }
}
