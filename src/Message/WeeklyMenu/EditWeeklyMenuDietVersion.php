<?php

declare(strict_types=1);

namespace WBoost\Web\Message\WeeklyMenu;

use Ramsey\Uuid\UuidInterface;

readonly final class EditWeeklyMenuDietVersion
{
    public function __construct(
        public UuidInterface $dietVersionId,
        public null|string $dietCodes,
        public null|string $items,
    ) {
    }
}
