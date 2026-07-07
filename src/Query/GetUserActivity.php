<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Builds the admin user-activity report from {@see \WBoost\Web\Entity\User}
 * (last-seen) and the user_activity_day counter table. Pure read model — raw
 * SQL aggregation, no entity hydration.
 */
readonly final class GetUserActivity
{
    /** Length of the daily time-series chart (days, ending today). */
    private const int CHART_DAYS = 30;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function overview(): UserActivityOverview
    {
        $connection = $this->entityManager->getConnection();

        // userId => [ 'YYYY-MM' => active days that month ], plus the ascending
        // list of months present in the data.
        [$activeDaysByUserMonth, $months] = $this->buildMonthlyActiveDays($connection);

        // Never-active users are kept (LEFT JOIN) so the report doubles as a
        // roster: last_activity_at NULL sorts last.
        $usersSql = <<<'SQL'
            SELECT
              u.id AS user_id,
              u.email,
              u.name,
              u.last_activity_at,
              COALESCE(a.active_days, 0) AS active_days,
              COALESCE(a.total_hits, 0) AS total_hits
            FROM "user" u
            LEFT JOIN (
              SELECT user_id, COUNT(*) AS active_days, SUM(hits) AS total_hits
              FROM user_activity_day
              GROUP BY user_id
            ) a ON a.user_id = u.id
            ORDER BY u.last_activity_at DESC NULLS LAST, u.email ASC
        SQL;

        $users = [];
        $activeUserCount = 0;

        foreach ($connection->executeQuery($usersSql)->fetchAllAssociative() as $row) {
            $lastActivityAt = $row['last_activity_at'] !== null
                ? new DateTimeImmutable($this->asString($row['last_activity_at']))
                : null;

            if ($lastActivityAt !== null) {
                $activeUserCount++;
            }

            $userId = $this->asString($row['user_id']);

            $users[] = new UserActivityRow(
                $userId,
                $this->asString($row['email']),
                $row['name'] !== null ? $this->asString($row['name']) : null,
                $lastActivityAt,
                $this->asInt($row['active_days']),
                $this->asInt($row['total_hits']),
                $activeDaysByUserMonth[$userId] ?? [],
            );
        }

        [$categories, $activeUsersSeries, $actionsSeries] = $this->buildDailySeries($connection);

        return new UserActivityOverview(
            $users,
            $activeUserCount,
            $months,
            $this->buildMonthCategories($months),
            $this->activeUsersByMonth($activeDaysByUserMonth, $months),
            $categories,
            $activeUsersSeries,
            $actionsSeries,
        );
    }

    /**
     * @return array{array<string, array<string, int>>, list<string>}
     */
    private function buildMonthlyActiveDays(Connection $connection): array
    {
        // One (user, day) row is unique, so COUNT(DISTINCT day) == active days.
        $sql = <<<'SQL'
            SELECT
              user_id,
              to_char(date_trunc('month', day), 'YYYY-MM') AS month,
              COUNT(DISTINCT day) AS active_days
            FROM user_activity_day
            GROUP BY user_id, date_trunc('month', day)
        SQL;

        $byUser = [];
        $monthsSet = [];

        foreach ($connection->executeQuery($sql)->fetchAllAssociative() as $row) {
            $userId = $this->asString($row['user_id']);
            $month = $this->asString($row['month']);
            $byUser[$userId][$month] = $this->asInt($row['active_days']);
            $monthsSet[$month] = true;
        }

        $months = array_keys($monthsSet);
        sort($months); // 'YYYY-MM' sorts chronologically as a string

        return [$byUser, $months];
    }

    /**
     * How many distinct users were active in each month.
     *
     * @param array<string, array<string, int>> $activeDaysByUserMonth
     * @param list<string> $months
     *
     * @return array<string, int> 'YYYY-MM' => active user count
     */
    private function activeUsersByMonth(array $activeDaysByUserMonth, array $months): array
    {
        $counts = array_fill_keys($months, 0);

        foreach ($activeDaysByUserMonth as $userMonths) {
            foreach ($userMonths as $month => $days) {
                if ($days > 0) {
                    $counts[$month]++;
                }
            }
        }

        return $counts;
    }

    /**
     * @param list<string> $months
     *
     * @return list<string> x-axis labels ('M/YYYY') aligned to $months
     */
    private function buildMonthCategories(array $months): array
    {
        return array_map(
            static function (string $month): string {
                [$year, $monthNumber] = explode('-', $month);

                return ((int) $monthNumber) . '/' . $year;
            },
            $months,
        );
    }

    /**
     * @return array{list<string>, list<int>, list<int>}
     */
    private function buildDailySeries(Connection $connection): array
    {
        $today = $this->clock->now()->setTime(0, 0);
        $from = $today->modify('-' . (self::CHART_DAYS - 1) . ' days');

        $dailySql = <<<'SQL'
            SELECT day, COUNT(DISTINCT user_id) AS active_users, COALESCE(SUM(hits), 0) AS actions
            FROM user_activity_day
            WHERE day >= :from
            GROUP BY day
        SQL;

        // 'YYYY-MM-DD' => [activeUsers, actions]
        $byDay = [];
        foreach ($connection->executeQuery($dailySql, ['from' => $from->format('Y-m-d')])->fetchAllAssociative() as $row) {
            $day = substr($this->asString($row['day']), 0, 10);
            $byDay[$day] = [$this->asInt($row['active_users']), $this->asInt($row['actions'])];
        }

        $categories = [];
        $activeUsersSeries = [];
        $actionsSeries = [];

        $cursor = $from;
        for ($i = 0; $i < self::CHART_DAYS; $i++) {
            $key = $cursor->format('Y-m-d');
            $categories[] = $cursor->format('j.n.');
            $activeUsersSeries[] = $byDay[$key][0] ?? 0;
            $actionsSeries[] = $byDay[$key][1] ?? 0;
            $cursor = $cursor->modify('+1 day');
        }

        return [$categories, $activeUsersSeries, $actionsSeries];
    }

    private function asString(mixed $value): string
    {
        assert(is_string($value));

        return $value;
    }

    private function asInt(mixed $value): int
    {
        assert(is_numeric($value));

        return (int) $value;
    }
}
