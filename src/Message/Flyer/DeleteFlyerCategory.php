<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Flyer;

use Ramsey\Uuid\UuidInterface;

readonly final class DeleteFlyerCategory
{
    public function __construct(
        public UuidInterface $categoryId,
    ) {
    }
}
