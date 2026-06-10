<?php

declare(strict_types=1);

namespace WBoost\Web\Message\CustomTemplate;

readonly final class SortCustomTemplates
{
    public function __construct(
        /** @var array<string> */
        public array $templates,
    ) {
    }
}
