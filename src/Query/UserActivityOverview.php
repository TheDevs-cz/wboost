<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

/**
 * The admin user-activity view-model: every user with their last-seen timestamp
 * and lifetime activity counts, plus a zero-filled daily time-series
 * (active users + total actions) ready to hand to ApexCharts.
 */
readonly final class UserActivityOverview
{
    /**
     * @param list<UserActivityRow> $users ordered by last activity desc (never-active last)
     * @param list<string> $months 'YYYY-MM' ascending — every month that has activity
     * @param list<string> $monthCategories column labels ('M/YYYY') aligned to $months
     * @param array<string, int> $activeUsersByMonth 'YYYY-MM' => distinct active users that month
     * @param list<string> $chartCategories x-axis day labels ('j.n.') ascending
     * @param list<int> $activeUsersSeries distinct active users per day, aligned to $chartCategories
     * @param list<int> $actionsSeries total activity pings per day, aligned to $chartCategories
     */
    public function __construct(
        public array $users,
        public int $activeUserCount,
        public array $months,
        public array $monthCategories,
        public array $activeUsersByMonth,
        public array $chartCategories,
        public array $activeUsersSeries,
        public array $actionsSeries,
    ) {
    }

    public function hasActivity(): bool
    {
        return $this->activeUserCount > 0;
    }

    public function activeUsersInMonth(string $month): int
    {
        return $this->activeUsersByMonth[$month] ?? 0;
    }
}
