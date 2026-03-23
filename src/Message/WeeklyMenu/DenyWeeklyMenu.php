<?php

declare(strict_types=1);

namespace WBoost\Web\Message\WeeklyMenu;

use Ramsey\Uuid\UuidInterface;

readonly final class DenyWeeklyMenu
{
    public function __construct(
        public UuidInterface $menuId,
        public string $hash,
        public null|string $comment = null,
    ) {
    }
}
