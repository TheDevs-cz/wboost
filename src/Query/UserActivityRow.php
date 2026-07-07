<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use DateTimeImmutable;

/**
 * One user in the admin activity report: when they were last seen and how
 * intensively they have used the app since tracking began.
 */
readonly final class UserActivityRow
{
    /**
     * @param array<string, int> $activeDaysByMonth 'YYYY-MM' => active days that month
     */
    public function __construct(
        public string $userId,
        public string $email,
        public null|string $name,
        public null|DateTimeImmutable $lastActivityAt,
        public int $activeDays,
        public int $totalHits,
        public array $activeDaysByMonth,
    ) {
    }

    public function displayName(): string
    {
        return $this->name ?? $this->email;
    }

    public function activeDaysInMonth(string $month): int
    {
        return $this->activeDaysByMonth[$month] ?? 0;
    }
}
