<?php

declare(strict_types=1);

namespace WBoost\Web\Message\WeeklyMenu;

use Ramsey\Uuid\UuidInterface;

readonly final class CopyWeeklyMenu
{
    public function __construct(
        public UuidInterface $originalMenuId,
        public UuidInterface $newMenuId,
        public \DateTimeImmutable $validFrom,
        public \DateTimeImmutable $validTo,
    ) {
    }
}
