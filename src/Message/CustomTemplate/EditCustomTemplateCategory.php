<?php

declare(strict_types=1);

namespace WBoost\Web\Message\CustomTemplate;

use Ramsey\Uuid\UuidInterface;

readonly final class EditCustomTemplateCategory
{
    public function __construct(
        public UuidInterface $categoryId,
        public string $name,
    ) {
    }
}
