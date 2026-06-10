<?php

declare(strict_types=1);

namespace WBoost\Web\Message\CustomTemplate;

use Ramsey\Uuid\UuidInterface;

readonly final class AddCustomTemplateCategory
{
    public function __construct(
        public UuidInterface $projectId,
        public string $name,
    ) {
    }
}
