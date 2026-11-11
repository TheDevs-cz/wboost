<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Calendar;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\Calendar\CalendarActor;
use WBoost\Web\Value\Calendar\CalendarExportVariant;

/**
 * Somebody asked for a PDF of the calendar. Records it in the history and
 * creates the `calendar_export` row `$exportId` — unless an export of the
 * same variant of the unchanged calendar is already there (finished or on
 * its way), which then answers the request. Dispatch through
 * {@see \WBoost\Web\Services\Calendar\CalendarExports::request()}, which also
 * starts the job.
 */
readonly final class RequestCalendarExport
{
    public function __construct(
        public UuidInterface $exportId,
        public UuidInterface $calendarId,
        public CalendarExportVariant $variant,
        public CalendarActor $actor,
        /** false = always create the row (the console command that measures an export) */
        public bool $reuse = true,
    ) {
    }
}
