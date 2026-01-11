<?php

declare(strict_types=1);

namespace WBoost\Web\Message\WeeklyMenu;

use Ramsey\Uuid\UuidInterface;

readonly final class DeleteWeeklyMenuDietVersion
{
    public function __construct(
        public UuidInterface $dietVersionId,
    ) {
    }
}
