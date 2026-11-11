<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Calendar;

use Ramsey\Uuid\UuidInterface;

/**
 * The PDF export job: render the calendar of this `calendar_export` row,
 * store the file, mark the row done / failed. Routed to the `async`
 * transport and dispatched on the `job_bus` (no transaction around it — see
 * config/packages/messenger.php).
 */
readonly final class RunCalendarExport
{
    public function __construct(
        public UuidInterface $exportId,
    ) {
    }
}
