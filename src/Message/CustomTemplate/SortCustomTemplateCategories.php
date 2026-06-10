<?php

declare(strict_types=1);

namespace WBoost\Web\Message\CustomTemplate;

readonly final class SortCustomTemplateCategories
{
    public function __construct(
        /** @var array<string> */
        public array $categories,
    ) {
    }
}
