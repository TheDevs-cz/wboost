<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Flyer;

use Ramsey\Uuid\UuidInterface;

readonly final class DeleteFlyerTemplate
{
    public function __construct(
        public UuidInterface $templateId,
    ) {
    }
}
