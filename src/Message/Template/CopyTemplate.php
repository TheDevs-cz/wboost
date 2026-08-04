<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

use Ramsey\Uuid\UuidInterface;

readonly final class CopyTemplate
{
    public function __construct(
        public UuidInterface $originalTemplateId,
        public UuidInterface $newTemplateId,
    ) {
    }
}
