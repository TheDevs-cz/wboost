<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Flyer;

use Ramsey\Uuid\UuidInterface;

readonly final class AddFlyerCategory
{
    public function __construct(
        public UuidInterface $projectId,
        public string $name,
    ) {
    }
}
