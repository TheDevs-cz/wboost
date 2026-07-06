<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateGroup;

use Ramsey\Uuid\UuidInterface;

readonly final class DeleteTemplateGroup
{
    public function __construct(
        public UuidInterface $groupId,
        public bool $deleteTemplates,
    ) {
    }
}
