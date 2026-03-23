<?php

declare(strict_types=1);

namespace WBoost\Web\Message\WeeklyMenu;

use Ramsey\Uuid\UuidInterface;

readonly final class RequestWeeklyMenuApproval
{
    public function __construct(
        public UuidInterface $menuId,
        public string $requestedByEmail,
    ) {
    }
}
