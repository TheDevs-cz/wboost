<?php

declare(strict_types=1);

namespace WBoost\Web\Message\CustomTemplate;

use Ramsey\Uuid\UuidInterface;

readonly final class DeleteCustomTemplateVariant
{
    public function __construct(
        public UuidInterface $variantId,
    ) {
    }
}
