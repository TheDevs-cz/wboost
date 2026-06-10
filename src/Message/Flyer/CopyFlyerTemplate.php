<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Flyer;

use Ramsey\Uuid\UuidInterface;

readonly final class CopyFlyerTemplate
{
    public function __construct(
        public UuidInterface $originalTemplateId,
        public UuidInterface $newTemplateId,
    ) {
    }
}
