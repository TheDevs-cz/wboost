<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

use Ramsey\Uuid\UuidInterface;

readonly final class DeleteTemplate
{
    public function __construct(
        public UuidInterface $templateId,
    ) {
    }
}
