<?php

declare(strict_types=1);

namespace WBoost\Web\Message\WeeklyMenuPlanner;

use Ramsey\Uuid\UuidInterface;

readonly final class RemoveDay
{
    public function __construct(
        public UuidInterface $dayId,
    ) {
    }
}
