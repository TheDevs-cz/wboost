<?php

declare(strict_types=1);

namespace WBoost\Web\Message\CustomTemplate;

use Ramsey\Uuid\UuidInterface;

readonly final class DeleteCustomTemplateCategory
{
    public function __construct(
        public UuidInterface $categoryId,
    ) {
    }
}
