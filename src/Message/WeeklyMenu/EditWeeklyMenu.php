<?php

declare(strict_types=1);

namespace WBoost\Web\Message\WeeklyMenu;

use Ramsey\Uuid\UuidInterface;

readonly final class EditWeeklyMenu
{
    public function __construct(
        public UuidInterface $menuId,
        public string $name,
        public \DateTimeImmutable $validFrom,
        public \DateTimeImmutable $validTo,
        public null|string $createdBy = null,
        public null|string $approvedBy = null,
    ) {
    }
}
