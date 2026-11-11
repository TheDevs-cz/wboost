<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Fakes;

use WBoost\Web\Entity\Calendar;
use WBoost\Web\Services\Calendar\CalendarPdfProducerInterface;
use WBoost\Web\Value\Calendar\CalendarExportVariant;

/**
 * Stands in for node + Gotenberg in the export tests: a tiny PDF whose body
 * names the calendar, the variant and the revision it was "rendered" from.
 * Reports progress like the real producer — (0, total), then twice more.
 *
 * `$failWith` makes the next call throw (and is cleared by it); `$calls`
 * records every render so a test can tell a reused export from a new job.
 * Both are static — the kernel (and with it this service) is rebuilt between
 * the requests of a test, and PHPStan reads the DEV container, where this
 * class is no service to fetch. A test that uses them calls {@see reset()}
 * first.
 */
final class FakeCalendarPdfProducer implements CalendarPdfProducerInterface
{
    public const int PAGES = 4;

    public static null|\Throwable $failWith = null;

    /** @var list<array{calendarId: string, variant: string, revision: int}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$failWith = null;
        self::$calls = [];
    }

    public function produce(Calendar $calendar, CalendarExportVariant $variant, string $directory, callable $progress): string
    {
        self::$calls[] = ['calendarId' => $calendar->id->toString(), 'variant' => $variant->value, 'revision' => $calendar->revision];

        $progress(0, self::PAGES);

        if (self::$failWith !== null) {
            $failure = self::$failWith;
            self::$failWith = null;

            throw $failure;
        }

        $progress(2, self::PAGES);
        $progress(self::PAGES, self::PAGES);

        $path = $directory . '/merged.pdf';
        file_put_contents($path, sprintf("%%PDF-1.4\n%% fake %s %s r%d\n%%%%EOF\n", $calendar->id->toString(), $variant->value, $calendar->revision));

        return $path;
    }
}
