<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use DateTimeImmutable;
use DateTimeZone;
use WBoost\Web\Value\AiScan\AiScanLimits;

/**
 * Time helpers shared by the scan services' raw SQL. Timestamps are stored the
 * way Doctrine stores them (`TIMESTAMP(0)`, the server's default time zone);
 * days and months of limits are Europe/Prague.
 */
final class AiScanTime
{
    public static function sql(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    public static function fromSql(null|string $value): null|DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $at = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($value, 0, 19), new DateTimeZone(date_default_timezone_get()));

        return $at === false ? null : $at;
    }

    /** The reserve's month: `YYYY-MM` in Europe/Prague. */
    public static function period(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone(AiScanLimits::TIMEZONE))->format('Y-m');
    }

    /** Midnight (Europe/Prague) of the day `$at` falls in. */
    public static function startOfDay(DateTimeImmutable $at): DateTimeImmutable
    {
        return $at->setTimezone(new DateTimeZone(AiScanLimits::TIMEZONE))->setTime(0, 0);
    }
}
