<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

use Ramsey\Uuid\UuidInterface;

readonly final class AddTemplateCategory
{
    public function __construct(
        public UuidInterface $projectId,
        public string $name,
    ) {
    }
}
