<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

use Ramsey\Uuid\UuidInterface;

readonly final class DeleteTemplateCategory
{
    public function __construct(
        public UuidInterface $categoryId,
    ) {
    }
}
